# Makefile shortcuts

Commands for your local dev machine. Both rely on the `cantrip` SSH alias being configured in `~/.ssh/config` — adjust `STAGING_HOST` / `PROD_HOST` in the `Makefile` if your alias differs.

The two destinations are kept separate so a `logs-prod` pull never overwrites the staging logs you just pulled, and vice versa. Both `storage/logs-s/` and `storage/logs-p/` are tracked in git (so the directories exist on a fresh clone), but their contents are gitignored — anything you `scp` in stays local.

## `make logs-staging`

Downloads all log files from the staging server into the local `storage/logs-s/` directory. Use this when investigating staging-only behavior — e.g. failed cron jobs, dev-environment errors, or scryfall sync diagnostics.

## `make logs-prod`

Downloads all log files from the production server into the local `storage/logs-p/` directory. Production logs may contain user-affecting data (request paths, IDs, exception traces); treat them accordingly — don't paste raw lines into public channels without scrubbing.
