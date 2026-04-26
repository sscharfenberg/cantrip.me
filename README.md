# cantrip.me

A Magic: The Gathering card collection manager with a focus on UX: Dark/Light mode. Multi-language. Accessibility first. Responsive. Fast.

**Work-In-Progress!**

**Stack:** Laravel 13 / PHP 8.2 · Vue 3 + TypeScript · Inertia.js · Vite · SCSS · MariaDB · Vue-i18n (de/en) · Laravel Fortify (auth + 2FA TOTP)

## Requirements

* PHP 8.2+
* Composer
* Node 24.11+ / npm 11.3+
* MariaDB
* `27.6 Gb` of harddisk space for image and json downloads. This will increase over time.

## Installation

```bash
composer setup
```

This runs: `composer install` → copy `.env.example` → `key:generate` → `migrate` → `npm install` → `npm run build`.

After setup, configure `.env` with your database credentials and `APP_URL` and `APP_CONTACT`.

Then create the storage symlinks so that public disks (set icons, symbols, art crops, card images) are accessible from the web:

```bash
php artisan storage:link
```

## Database seeding

Seeding requires Scryfall data to be present in the database (the `default_cards` table). Run the full Scryfall sync first:

```bash
php artisan scryfall:update
```

Then seed containers and card stacks:

```bash
php artisan db:seed
```

The seeder creates the test user, wipes existing containers and card stacks for the first user, creates 10 sample containers, and distributes 60 random cards across them. Needs populated oracle_cards and default_cards tables.

## Setup IDE for development

I am using IntelliJ, other IDEs probably work as well; I just don't know them.

### A) Prettier

Prettier needs to be run on save.

#### IntelliJ

* `Settings` → `Languages & Frameworks` → `Javascript` → `Prettier`
* Select `Automatic Prettier configuration`
* Run for files: `**/*.{js,ts,json,vue,scss}`
* `Run on save` must be checked

### B) ESLint

ESLint should be run while editing in the IDE.

#### IntelliJ

* `Settings` → `Languages & Frameworks` → `Javascript` → `Code Quality Tools` → `ESLint`
* Select `Automatic ESLint configuration`
* Run for files: `**/*.{js,ts,html,vue}`
* `Run on eslint --fix on save` must be checked.

### C) Stylelint

StyleLint should be run while editing in the IDE. This does not work well in `.vue` files currently.

#### IntelliJ

* `Settings` → `Languages & Frameworks` → `Style Sheets` → `Stylelint`
* Select `Enable`
* Run for files: `**/*.{scss, vue}`
* `Run on stylelint --fix on save` must be checked

## Artisan commands

### `php artisan scryfall:update`

Runs all Scryfall commands in sequence. Use this for a daily cronjob.
Warning: downloads ~600MB of bulk JSON data from Scryfall per run if in production. Other envs, only downloads once and keeps the downloaded JSON files.

In production, the app is put into maintenance mode (`artisan down`) for the duration and brought back up (`artisan up`) when done.

The execution order is:

```
scryfall:sets           → fetch set metadata + download set icon SVGs
scryfall:symbols        → fetch mana/ability symbols + download symbol SVGs
scryfall:bulk           → download bulk data metadata (URLs, expected filesizes)
scryfall:oracle         → import oracle cards from bulk JSON into oracle_cards table
scryfall:default_cards  → import default cards from bulk JSON into default_cards + artists tables
scryfall:images         → download missing/outdated art crops + card images to local disk
scryfall:resolve-paths  → update Scryfall URLs in database to point at local image paths
scryfall:cache          → pre-warm the welcome-page Scryfall stats cache so the next visitor lands on a warm page
```

#### Design: separation of concerns for image handling

Image handling is split across three services, each with a single responsibility:

1. **Import** (`DefaultCardsService`, `OracleCardsService`) → stores raw Scryfall image URLs in the database. No disk access, no path resolution.
2. **Download** (`ImageDownloadService`) → reads Scryfall URLs from the database, downloads images to local disk. Filesystem only, no database writes.
3. **Resolve** (`ResolveImagePathsService`) → walks database records that still have Scryfall URLs, checks if the corresponding local file exists, and updates the URL to a local path. Database only, no downloads.

This separation means each step can be re-run independently. If a download is interrupted, re-running `scryfall:images` picks up where it left off. If images are on disk but the database still has Scryfall URLs (e.g. after a re-import), `scryfall:resolve-paths` fixes the references without re-downloading anything.

#### Image caching strategy

Scryfall image URLs contain a cache-busting timestamp in the query string (e.g. `?1709234567`). This timestamp is embedded in the local filename (`uuid--1709234567.jpg`), so when Scryfall updates an image, the old local file is automatically replaced on the next download cycle.

### `php artisan scryfall:sets`

Fetches all sets from the Scryfall API and upserts them into the `sets` table. Downloads set icon SVGs to the `set` storage disk if not already cached.

### `php artisan scryfall:symbols`

Fetches all mana and ability symbols from the Scryfall API and upserts them into the `symbols` table. Downloads symbol SVGs to the `symbol` storage disk if not already cached.

### `php artisan scryfall:bulk`

Fetches bulk data metadata from Scryfall (download URLs and expected filesizes) and stores it in the `bulkdata` table. This information is required by `scryfall:oracle` and `scryfall:default_cards` to download the correct bulk JSON files.

### `php artisan scryfall:oracle`

Downloads the `oracle_cards` bulk JSON from Scryfall (if not already cached), truncates the `oracle_cards` table, and stream-parses the JSON to insert each card. Image columns (`card_image_0`, `card_image_1`) are stored as Scryfall URLs; local path resolution happens later via `scryfall:resolve-paths`.

### `php artisan scryfall:default_cards`

Downloads the `default_cards` bulk JSON from Scryfall (if not already cached), truncates the `default_cards` and `artists` tables, and stream-parses the JSON to insert each card. Image columns (`card_image_0`, `card_image_1`, `art_crop`) are stored as Scryfall URLs; local path resolution happens later via `scryfall:resolve-paths`.

### `php artisan scryfall:images`

Walks the `default_cards` table looking for rows that still have Scryfall URLs (i.e. images not yet cached locally). Downloads art crops to the `art-crops` storage disk and card images to the `card-images` storage disk. Does not modify the database — that is the job of `scryfall:resolve-paths`.

This is potentially a long-running command:
* ~8 hours on a cold cache (initial download of all images)
* ~20 seconds on a hot cache (no images need downloading)

Total image cache currently needs about `25 Gb` of image files.

### `php artisan scryfall:resolve-paths`

Walks `default_cards` and `oracle_cards` looking for rows that still have Scryfall URLs in their image columns. For each row, checks if the corresponding local file exists on disk and, if so, updates the database column to the local path (e.g. `/art-crops/lea/uuid--1709234567.jpg`).

For oracle cards, the local path is copied from a matching default card (looked up via `oracle_id`), since oracle cards share images with their default card printings.

### `php artisan scryfall:cache`

Refreshes the welcome-page Scryfall stats cache (full-table COUNTs of `oracle_cards` / `default_cards` / `sets` / `artists`, plus recursive `find` + `du` over the on-disk art-crop and card-image directories). The result is keyed under `welcome.scryfallStats` and stored with `Cache::forever`, so it stays valid until the next sync explicitly replaces it.

`scryfall:update` runs this as its final step so visitors right after a sync don't pay the cold filesystem-walk cost. Run it manually if you've wiped the cache table or otherwise need to repopulate the entry without doing a full sync — it takes a few seconds at most.

## Scheduled tasks

Laravel's task scheduler handles recurring jobs (e.g. temporary file cleanup). To activate it, add this cron entry for the web server user:

```bash
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

This runs `schedule:run` every minute; Laravel determines internally which scheduled tasks are due. Scheduled tasks are defined in `routes/console.php`.

## Development

### `composer dev`

Starts all development services in parallel (via `concurrently`):
* `php artisan serve` — Laravel dev server
* `php artisan queue:listen` — Queue worker
* `php artisan pail` — Real-time log viewer
* `npm run dev` — Vite dev server

### `composer test`

Runs PHPUnit against the default SQLite in-memory driver configured in `phpunit.xml`. Fast, local, no DB setup required — covers all unit tests and feature tests that do not depend on MariaDB-specific features.

```bash
composer test                                         # full suite
composer test -- --filter=DeckCardSearchServiceTest   # filtered (note the `--`)
```

### `composer test:mysql`

Runs the same suite but against MariaDB. Use this on staging (or any server with a populated `mbos` database) to exercise feature tests that require real Scryfall data and MariaDB-only SQL (`REGEXP` color-identity filters, accent-folding collations).

```bash
# On staging
composer test:mysql                                   # full suite
composer test:mysql -- --filter=DeckServiceTest       # filtered (note the `--`)
```

**Prerequisites:**
* `DB_CONNECTION=mysql` and `DB_DATABASE=mbos` must point at a real MariaDB instance. The composer script injects these as inline shell environment variables before `php artisan test` so they beat `phpunit.xml`'s non-forced `<env>` tags — do not override them on the CLI.
* The database must contain a full Scryfall import. Run `php artisan scryfall:update` first — the MariaDB-only tests assert on bedrock cards (Sol Ring, Lightning Bolt, Atraxa, Yoshimaru, etc.) that only exist after the sync.
* `phpunit.xml` must exist on the target machine (it is not `.dist`-suffixed, so PhpStorm deployment must not exclude it).
* If you recently ran `php artisan config:cache`, clear it first with `php artisan config:clear` — the test scripts no longer do this implicitly (it conflicts with forwarding `--filter` through the script chain).

Tests in `tests/Feature/Services/` that need MariaDB self-skip with a `markTestSkipped()` guard when `DB::connection()->getDriverName() !== 'mysql'`, so running `composer test` locally silently drops them instead of failing. Run `composer test:mysql` on staging whenever those skipped tests matter.

## NPM commands

| Command | Description |
|---------|-------------|
| `npm run dev` | Vite dev server (HMR) |
| `npm run build` | Lint + type-check + Vite production build + icon processing |
| `npm run lint` | ESLint + Stylelint with auto-fix |
| `npm run format` | Prettier |
| `npm run type-check` | `vue-tsc --build` |
| `npm run icons` | Process SVG icons into sprite sheet |

### Vite dev server

Ensure `.env` has `APP_ENV=local`, `APP_DEBUG=true`, and `APP_URL` pointing to the correct host. The `public/hot` file must be present for Vite HMR to work.

### Production build

Ensure `.env` has `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL` pointing to the production domain. The `public/hot` file must *not* be present.

## Makefile shortcuts

### `make logs-staging`

Command for your local dev machine. Downloads all log files from the staging server into the local `storage/logs/` directory.

Uses the `cantrip` SSH alias (configured in `~/.ssh/config`). Since `storage/logs/` is excluded from IntelliJ's deployment sync, this is the quickest way to pull logs locally for inspection. Update with your host.

## License
`cantrip.me` is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## A note on AI usage
`cantrip.me` contains code that was written by a coding assistant, following strict guidelines on how to structure and architect the code. Every part that was not authored by a human has been reviewed and tested by a human.
