# Artisan commands

Project-specific commands for Scryfall data sync and image management. Standard Laravel commands (`migrate`, `tinker`, etc.) are not documented here.

## Scryfall sync

### `php artisan scryfall:update`

Orchestrates the full Scryfall sync via the **shadow-table swap flow**: builds every truncate-rebuild table in `*__shadow` siblings, validates FK integrity, captures+drops FKs around an atomic multi-table RENAME, then re-adds FKs to the new live tables. The site stays fully usable throughout — DB churn is invisible to users, the swap itself is sub-second, and a mid-flight failure leaves the live tables byte-for-byte untouched. **No maintenance mode** (`down`/`up`).

Warning: downloads ~700 MB of bulk JSON data from Scryfall per run if in production, plus another ~2.5 GB streamed (not stored) for the foreign-language translations. Other environments only download the disk-backed bulks once and keep them on disk for subsequent runs; the streamed `all_cards` bulk is re-fetched every run regardless of environment.

Execution order:

```
cleanup                 → (drop any leftover __shadow / __retired from a crashed prior run)
createLike              → create all 12 shadow tables (empty)
scryfall:bulk           → bulk_data__shadow (writes download URIs)
scryfall:sets           → sets__shadow (HTTP fetch + set icon SVGs)
scryfall:symbols        → symbols__shadow (HTTP fetch + symbol SVGs)
scryfall:oracle         → oracle_cards__shadow + oracle_card_faces__shadow + legalities__shadow
scryfall:oracle-tags    → UPDATEs oracle_cards__shadow with otag-derived columns
scryfall:translations   → oracle_card_translations__shadow + oracle_card_face_translations__shadow (streams ~2.5 GB all_cards from Scryfall, no on-disk file)
scryfall:default_cards  → default_cards__shadow + default_card_relations__shadow + artists__shadow
scryfall:rulings        → rulings__shadow
scryfall:images         → downloads art crops + card images to disk (no DB writes)
scryfall:resolve-paths  → UPDATEs default_cards__shadow.image columns to local paths
validate orphans        → LEFT JOINs every FK relation against the shadow build
captureForeignKeys      → snapshot every FK referencing the 12 swap tables
dropForeignKeys         → drop them so the RENAME doesn't auto-rotate references
swap                    → atomic multi-table RENAME (12 lives → __retired, 12 shadows → live)
dropRetired             → drop the 12 __retired tables
addForeignKeys          → re-add the captured FKs to the new live tables
scryfall:cache          → refresh welcome-page Scryfall stats cache
gc-images dry scan      → reports orphan files; warns with the --prune command if any (never deletes from this flow)
end-of-run summary      → row counts per table, oracle-tag classifications, FK preservation, image-orphan totals, runtime
```

The summary block is emitted at the very end of the run as a single formatted block so the totals are visible at a glance without scrolling back through 232k+ image-resolution log lines.

### `php artisan scryfall:sets`

Fetches all sets from `/sets` and inserts into `sets`. Downloads set icon SVGs to the `set` storage disk if not already cached. Supports `--target=live|shadow` (default `live`).

### `php artisan scryfall:symbols`

Fetches all mana and ability symbols from `/symbology` and inserts into `symbols`. Downloads symbol SVGs to the `symbol` storage disk if not already cached. Supports `--target=live|shadow`.

### `php artisan scryfall:bulk`

Fetches bulk-data metadata from Scryfall (download URLs, expected filesizes) and stores in `bulk_data`. Required by `scryfall:oracle` / `scryfall:default_cards` / `scryfall:rulings` to find the correct download URLs. Supports `--target=live|shadow`.

### `php artisan scryfall:oracle`

Downloads the `oracle_cards` bulk JSON (if not already cached), truncates `oracle_cards` / `oracle_card_faces` / `legalities` (or skips truncate in shadow mode — orchestrator pre-created the empty shadows), and stream-parses the JSON to insert each card. Card-level fields including `produced_mana` live on `oracle_cards`; per-face data on `oracle_card_faces`. Image columns are stored as Scryfall URLs; local path resolution happens later via `scryfall:resolve-paths`. Supports `--target=live|shadow`.

### `php artisan scryfall:oracle-tags`

Syncs every Scryfall oracle-tag → `oracle_cards` column mapping in one pass. Each mapping is a strategy under `App\Services\Scryfall\OracleTagSyncs\` — the orchestrator (`OracleTagsService`) handles the API call, pagination, ≥1s pacing, and transactional apply once; strategies just describe the tag, target column, clear value, and per-card value derivation.

Two strategies ship today:

- **`BooleanOracleTagSync`** — boolean column, presence in the search means `true`. Used for `mass-land-denial` → `oracle_cards.mld` (drives the bracket auto-suggest hint on the deck-edit page). Add a new boolean tag by registering one more `BooleanOracleTagSync(tag: '…', column: '…')` in the orchestrator's constructor.
- **`FetchPatternOracleTagSync`** — string column, value derived by parsing each card's oracle text. Powers `otag:fetchland` → `oracle_cards.fetch_pattern`. Pattern format (always WUBRG-sorted):
  - `basic` — any basic land (Fabled Passage, Evolving Wilds, Field of Ruin, …)
  - `basic:<colors>` — basic of one or more specific land types (Bant Panorama → `basic:WUG`)
  - `typed:<colors>` — typed land (basic OR non-basic) with one of the listed land subtypes (Polluted Delta → `typed:UB`)
  - `any` — any land card, no type filter (Urza's Cave)

Tags are not in Scryfall's bulk data — only reachable via `/cards/search?q=otag:<slug>`. The orchestrator paginates with ≥1s delay between every Scryfall call (across pages AND across syncs in the same run) and applies in a single transaction per sync (clear column → bulk-update grouped values in 1 000-id chunks). Zero results from a tag are treated as a sync failure (taxonomy probably renamed) and the column is **left untouched** rather than zeroed.

Runs in `scryfall:update` immediately after `scryfall:oracle` so the freshly inserted `oracle_cards` rows can be flagged in place. Supports `--target=live|shadow`.

### `php artisan scryfall:translations`

Imports foreign-language card-name translations from Scryfall's `all_cards` bulk (~2.5 GB) into `oracle_card_translations` (one row per `(oracle_card_id, lang)`) and `oracle_card_face_translations` (one row per `(oracle_card_id, face_index, lang)`). Drives the deck card-add / collection-add / commander search services so a German user can find "Blitzschlag" → Lightning Bolt.

**Unique among the scryfall sync commands: streams the bulk directly from Scryfall**, no on-disk file. The shared `BulkdataService::prepareJson()` helper used by `scryfall:oracle` / `scryfall:default_cards` / `scryfall:rulings` materializes the full HTTP response in PHP memory before writing to disk — fine for the smaller bulks but it OOMs on the 2.5 GB `all_cards` payload. Instead, this command engages cerbero's `Endpoint` source on `JsonParser::parse($download_uri)`, which reads the JSON via a PSR-7 stream wrapper. Tradeoff: a mid-stream abort means the next run re-fetches from Scryfall. No `scryfall-bulk` disk activity.

Skips English printings (already in `oracle_cards.name`), printings whose `oracle_id` is unknown (defensive — tokens, etc.), and reprints with a duplicate `(oracle, lang)` or `(oracle, face_index, lang)` key (first occurrence wins). Bulk-inserts deduped rows in 1 000-row chunks at end of walk.

Peak RAM ≈ ~65 MB (both buffers kept in scope across the full walk so dedupe can't be partial). End-of-run log line summarizes skip counts and dedupe hits.

Supports `--target=live|shadow`. The orchestrator invokes this with `--target=shadow` so FK lookups resolve against `oracle_cards__shadow` / `oracle_card_faces__shadow`.

### `php artisan scryfall:default_cards`

Downloads the `default_cards` bulk JSON (if not already cached), truncates `default_cards` / `default_card_relations` / `artists` (or skips in shadow mode), and stream-parses to insert each card. Image columns are stored as Scryfall URLs; resolution happens later via `scryfall:resolve-paths`.

`all_parts` (Scryfall's printing-level edges to related cards — tokens, meld parts, meld results, combo pieces) is captured during the same file walk into `default_card_relations`. Edges are buffered in memory and bulk-inserted (in 5 000-row chunks to stay under MySQL's 65 535-placeholder per-statement cap) once at end-of-walk.

**Orphan re-targeting.** Scryfall emits ~1 979 edges per bulk where `all_parts.id` references a printing not in `default_cards` (foreign-language UUID, excluded layout, printing variant Scryfall didn't pick for the bulk, etc.). `flushRelationsBuffer` builds a `searchable_name → default_cards.id` lookup and rewrites these orphan edges to point at a valid printing of the same oracle. Surfaced in console output as `re-targeted N orphan default_card_relations edges to default printings of the same oracle.`

Supports `--target=live|shadow`.

### `php artisan scryfall:rulings`

Downloads the `rulings` bulk JSON (~25 MB), truncates `rulings`, and stream-parses to insert each ruling. Pre-loads existing `oracle_cards.id` values into an in-memory hash so rulings whose `oracle_id` doesn't reference a card we have (tokens, etc.) are silently skipped without per-row FK lookups. Inserts in 500-row batches. Supports `--target=live|shadow`.

### `php artisan scryfall:images`

Walks the `default_cards` table looking for rows that still have Scryfall URLs (i.e. images not yet cached locally). Downloads art crops to the `art-crops` disk and card images to the `card-images` disk. Does not modify the database — that is the job of `scryfall:resolve-paths`.

Long-running:

* ~8 hours on a cold cache (initial download of all images)
* ~20 seconds on a hot cache (no images need downloading)

Total image cache currently ~25 GB.

Supports `--target=live|shadow`. The orchestrator invokes this with `--target=shadow` so newly-imported cards (sitting in `default_cards__shadow` with `https://…` URLs) are discoverable — querying live during a shadow build would return zero rows (every live row has already been resolved to a local path by the previous swap), and the new cards would survive the upcoming swap pointing at the Scryfall CDN with no local cache. The shadow query joins `sets__shadow` on `set_id` so brand-new sets are resolvable too.

### `php artisan scryfall:resolve-paths`

Walks `default_cards` looking for rows that still have Scryfall URLs in their image columns. For each row, checks if the corresponding local file exists on disk and, if so, updates the database column to the local path (e.g. `/art-crops/lea/uuid--1709234567.jpg`). Uses `chunkById()` (NOT `chunk()`) — OFFSET pagination silently skips rows once the WHERE result-set shrinks under updates. Supports `--target=live|shadow`.

### `php artisan scryfall:cache`

Refreshes the welcome-page Scryfall stats cache (full-table COUNTs of `oracle_cards` / `default_cards` / `sets` / `artists`, plus recursive `find` + `du` over the on-disk art-crop and card-image directories). The result is keyed under `welcome.scryfallStats` and stored with `Cache::forever`, so it stays valid until the next sync explicitly replaces it.

`scryfall:update` runs this as its final step. Run manually if you've wiped the cache table or otherwise need to repopulate the entry without doing a full sync — takes a few seconds at most.

## Maintenance commands

### `php artisan scryfall:gc-images`

Garbage-collects art crop and card image files whose UUID is no longer in `default_cards`. Two sources of orphans:

- **Per-card stale versions** of cards still in the bulk are handled inline by `ImageDownloadService::cleanupOldVersions()` during `scryfall:images`. Not in this command's scope.
- **Cards entirely removed from `default_cards`** (Scryfall data corrections, foreign-language reshuffles, etc.). Rare but accumulates over months — this command catches them.

Defaults to **dry-run** (reports counts, deletes nothing). Pass `--prune` to actually delete. `--art-crops-only` / `--card-images-only` to limit scope. Run periodically — monthly cron is plenty.

### `php artisan notifications:test-failure-alert`

Dispatches a synthetic `ScryfallUpdateFailedNotification` (mail + Discord embed) to verify the alert pipeline in isolation. Options:

* `--channel=mail|discord|both` (default `both`)
* `--command=<name>` (default `scryfall:oracle`) — embedded in the alert as the failing command

Used to verify mail+Discord wiring without breaking a real `scryfall:update` run.

## Database backups

Self-managed MySQL/MariaDB backup pair. Independent of the host-level Hetzner backups — these are restorable in-place via `db:restore` so the round-trip is actually testable.

Both commands work in every environment, but the daily schedule only fires in `production` so staging doesn't accumulate dumps from a DB it mostly mirrors anyway. Test on staging by running `php artisan db:backup` / `db:restore` manually.

Backups land in `config('cantrip.db_backup.path')` (default `storage/app/db-backups`). Retention is `config('cantrip.db_backup.retention_days')` (default 7), overridable per-env via the `DB_BACKUP_RETENTION_DAYS` env var.

### `php artisan db:backup`

Dumps the configured MySQL/MariaDB database via `mysqldump --single-transaction --quick --skip-lock-tables --default-character-set=utf8mb4`, then rotates older dumps to `.tar.gz` and prunes anything beyond the retention window.

Directory layout after a few days:

```
db-backup-2026-05-17.sql      ← today's dump (uncompressed, mode 0640)
db-backup-2026-05-16.tar.gz   ← yesterday, compressed
db-backup-2026-05-15.tar.gz
…
db-backup-2026-05-11.tar.gz   ← oldest still inside the 7-day window
```

Anything dated more than `retention_days` before today is unlinked on every run.

Credentials reach `mysqldump` via a 0600 temp file with `--defaults-extra-file`, so the DB password never appears in `ps` or `/proc/<pid>/cmdline`.

Options:

* `--retention-days=<n>` — override the retention window for this run (does not change the persisted config).

Scheduled daily at 00:15 in production via `routes/console.php`. Logs to the `schedule` channel (`storage/logs/schedule.log`, 14-day rotation): info on start/finish + bytes written, debug on individual compressions, error + non-zero exit on failure.

### `php artisan db:restore`

Restore from a `db:backup` dump. **Strictly manual** — no schedule, no automation. Listed via Laravel Prompts with newest-first ordering; pick one, confirm at the destructive-operation prompt, and the dump is piped straight into `mysql`.

Picker labels show filename + age + type + size:

```
db-backup-2026-05-17.sql (today) — sql — 148.14MB
db-backup-2026-05-16.tar.gz (1 day ago) — tar.gz — 12.34MB
db-backup-2026-05-10.tar.gz (7 days ago) — tar.gz — 12.30MB
```

`.tar.gz` archives are extracted to a fresh `/tmp/cantrip-restore-<hex>` directory, imported, and cleaned up in a `finally` block (whether the import succeeded or not — no orphaned multi-hundred-megabyte dumps in `/tmp` after a failure).

The SQL stream is piped to mysql's stdin via Symfony Process input — never buffered in PHP — so the memory footprint stays constant regardless of dump size.

Options:

* `--file=<path>` — restore a specific file directly, skipping the picker. Useful for scripting or restoring from a path outside the backup directory.
* `--force` — skip the destructive-operation confirmation prompt. Picker still applies unless `--file` is also passed.

Logs to the `schedule` channel: info on start/done, debug on archive extraction + cleanup, error + non-zero exit on failure.

**Verifying a backup is actually restorable** — pick today's dump, hit "yes" at the prompt, and check the app still works. The mysqldump `DROP TABLE IF EXISTS` defaults make the restore idempotent for tables that exist in both the dump and the current DB; tables only in the current DB but not the dump are left untouched (rare in practice).

## Scheduled tasks

Laravel's task scheduler handles recurring jobs. To activate, add this cron entry for the web server user:

```bash
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

Runs `schedule:run` every minute; Laravel determines internally which scheduled tasks are due. Scheduled tasks are defined in `routes/console.php`:

| Schedule | When | Environments |
|---|---|---|
| `CleanupTempUploads` job | daily at 00:00 | all |
| `db:backup` command | daily at 00:15 | production only |
