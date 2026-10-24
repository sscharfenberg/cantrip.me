#!/usr/bin/env bash
# Production deploy script — installed on the production server at
# /home/deploy/bin/deploy-prod.sh. Invoked over SSH from the
# `deploy-production` GitHub Actions workflow; the commit SHA is
# passed via $SSH_ORIGINAL_COMMAND (the deploy key's authorized_keys
# `command=` directive forces this script and routes the client's
# arg into $SSH_ORIGINAL_COMMAND).
#
# Flow: validate SHA (40 hex chars) → snapshot maintenance state → down
# → git reset --hard to the SHA → composer/npm build → migrate
# → artisan caches → conditionally up.
#
# `was_down` guard: a manual `php artisan down` survives a deploy —
# if the site was already in maintenance when this script started,
# we leave it that way instead of silently un-pausing it.
#
# This file is committed for history/review only; the live copy
# is on the server and edits must be replicated there.
set -euo pipefail
umask 002

export PATH="/usr/local/bin:/usr/bin:/bin"
export NVM_DIR="/home/deploy/.nvm"
# shellcheck source=/dev/null
. "$NVM_DIR/nvm.sh"

REF="${SSH_ORIGINAL_COMMAND:-}"
if ! [[ "$REF" =~ ^[0-9a-f]{40}$ ]]; then
  echo "ERROR: invalid or missing commit SHA (got: '${REF}')" >&2
  exit 1
fi

cd /var/www/mbop

# Snapshot maintenance state BEFORE we touch it. If the site was already
# in maintenance (e.g. someone ran `php artisan down` manually), we'll
# leave it that way at the end instead of silently un-pausing it.
if [ -f storage/framework/maintenance.php ]; then
    was_down=1
else
    was_down=0
fi

# Pre-render the 503 page now, while the old release is still intact.
# During maintenance public/index.php then serves this HTML straight from
# storage/framework/down without booting Laravel, so the page survives
# everything below: git reset rewriting the views, composer swapping
# vendor/, the caches being rebuilt. A plain `down` rendered errors::503
# live on every request, and once git reset had rewritten 503.blade.php
# (new mtime, same content) Blade tried to touch() the deploy-owned
# compiled view as www-data — only the owner may set a timestamp — so the
# render failed and Laravel fell back to Symfony's generic "Oops! An Error
# Occurred" page (prod, 2026-10-17).
php artisan down --render="errors::503"

git fetch origin

# Source dirs whose ownership and perms are normalized after the reset.
SOURCE_DIRS=(app database docs tests routes resources lang config public)

# Hand tracked files another user wrote back to git before the reset. An
# upload as sscharfenberg (tar-pipe, scp) normally comes back as deploy for
# free: its inode no longer matches the index, so `git reset --hard`
# rewrites it. But anything that refreshes the index in between — a
# `git status` on the server is enough — records the upload's own stat
# data, uid included. The file then looks clean, the reset leaves it alone,
# and the `chgrp` below dies with EPERM after `php artisan down` (staging,
# 2026-10-24). Deleting those files makes the reset recreate them as
# deploy. Only files git tracks are deleted, so an upload that was never
# committed is not lost (it still fails the chgrp, loudly). bootstrap/ is
# searched for its tracked *.php; the caches in bootstrap/cache are
# untracked and stay put. Prod gets no uploads in normal use, but the
# step costs nothing and matches staging.
find "${SOURCE_DIRS[@]}" bootstrap -type f ! -user deploy -print0 \
    | xargs -0 -r git --literal-pathspecs ls-files -z -- \
    | xargs -0 -r rm -fv --

git reset --hard "$REF"

# Wipe Vite's output dir before the chgrp + rebuild below. Any files
# left there from outside this script would otherwise fail `chgrp` —
# deploy can't change group on files it doesn't own. `npm run build`
# recreates the dir fresh as deploy-owned with the right perms (umask
# 002 + setgid on public/ → www-data group). Prod doesn't have a dev
# server but the defensive wipe costs nothing and matches staging.
rm -rf public/build

# Re-normalize source-tree perms. `git reset --hard` may write files
# with mode 644 (ignoring umask in some setups), and any subdir whose
# setgid bit was lost in the past creates new files in deploy's
# primary group instead of www-data. The `chgrp` step recovers group
# ownership, `chmod 2775` on dirs re-establishes setgid so future
# inheritance works on its own, and `chmod g+rw` on files matches the
# documented `664` convention. Scoped to source dirs so we don't touch
# vendor/, node_modules/, storage/, public/build/, or .git/ (each managed
# by its own tooling with its own perm needs).
#
# READ as well as write, and that is not pedantry: `g+w` alone turns a 600
# file into 620 — group-writable but still unreadable. An upload left
# resources/app/components/Landmarks/Header/logo.svg at 600, this step
# dutifully made it 620, and `npm run build` then died with EACCES for
# everyone except the file's owner. The build normally runs AS that owner,
# so it only surfaced when someone ran it by hand.
chgrp -R www-data "${SOURCE_DIRS[@]}"
find "${SOURCE_DIRS[@]}" -type d -exec chmod 2775 {} +
find "${SOURCE_DIRS[@]}" -type f ! -perm -g+rw -exec chmod g+rw {} +

# bootstrap/ is swept by hand rather than via SOURCE_DIRS, so that
# bootstrap/cache can be left out of it. Laravel rewrites the compiled
# manifests in there (packages.php, services.php) from whichever process
# boots the framework first while they are missing — and `schedule:run`
# runs from cron every minute as sscharfenberg, so deploy routinely does
# not own them. `chgrp` needs ownership even when the group is already
# correct, so including them here aborts the whole deploy with EPERM,
# and does so *after* `php artisan down`, which is how a failed deploy
# leaves the site stuck in maintenance mode.
#
# Skipping them costs nothing: bootstrap/cache carries setgid (2775), so
# anything created inside inherits the www-data group whoever writes it —
# which is why these files always show the right group and only ever the
# wrong owner. The chmod below keeps that setgid bit in place, so the
# guarantee is maintained rather than assumed. The caches themselves are
# rewritten further down by composer install (package:discover) and the
# config/route/view/event cache commands.
chgrp www-data bootstrap bootstrap/*.php bootstrap/cache
chmod 2775 bootstrap bootstrap/cache
chmod g+rw bootstrap/*.php

composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

if [ "$was_down" -eq 0 ]; then
    php artisan up
fi

echo "Deployed $REF to production."
