#!/usr/bin/env bash
# Production deploy script — installed on the production server at
# /home/deploy/bin/deploy-prod.sh. Invoked over SSH from the
# `deploy-production` GitHub Actions workflow; the version tag is
# passed via $SSH_ORIGINAL_COMMAND (the deploy key's authorized_keys
# `command=` directive forces this script and routes the client's
# arg into $SSH_ORIGINAL_COMMAND).
#
# Flow: validate tag (vX.Y.Z) → snapshot maintenance state → down
# → git reset --hard to the tag → composer/npm build → migrate
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

TAG="${SSH_ORIGINAL_COMMAND:-}"
if ! [[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "ERROR: invalid or missing tag (got: '${TAG}')" >&2
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

git fetch origin --tags
git reset --hard "$TAG"

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

echo "Deployed $TAG to production."
