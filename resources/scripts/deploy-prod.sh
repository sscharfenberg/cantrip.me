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

php artisan down

git fetch origin
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
# inheritance works on its own, and `chmod g+w` on files matches the
# documented `664` convention. Scoped to source dirs so we don't
# touch vendor/, node_modules/, storage/, public/build/, or .git/
# (each managed by its own tooling with its own perm needs).
SOURCE_DIRS=(app database docs tests routes resources lang bootstrap config public)
chgrp -R www-data "${SOURCE_DIRS[@]}"
find "${SOURCE_DIRS[@]}" -type d -exec chmod 2775 {} +
find "${SOURCE_DIRS[@]}" -type f ! -perm -g+w -exec chmod g+w {} +

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
