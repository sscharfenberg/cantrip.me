# cantrip.me

[![CI](https://github.com/sscharfenberg/cantrip.me/actions/workflows/ci.yml/badge.svg)](https://github.com/sscharfenberg/cantrip.me/actions/workflows/ci.yml)
[![PHPUnit](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fsscharfenberg%2Fcantrip.me%2Fbadges%2Fphpunit.json)](https://github.com/sscharfenberg/cantrip.me/actions/workflows/ci.yml)
[![Vitest](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fsscharfenberg%2Fcantrip.me%2Fbadges%2Fvitest.json)](https://github.com/sscharfenberg/cantrip.me/actions/workflows/ci.yml)

A Magic: The Gathering card collection manager with a focus on UX: Dark/Light mode. Multi-language. Accessibility first. Responsive. Fast. 

**Stack:** Laravel 13 / PHP 8.4 · Vue 3 + TypeScript · Inertia.js · Vite · SCSS · MariaDB · Vue-i18n (de/en) · Laravel Fortify (auth + 2FA TOTP)

## Requirements

* PHP 8.4+
* Composer
* Node 26.1+ / npm 11.13+
* MariaDB
* `~28 GB` of harddisk space for cached Scryfall images (art crops + card images). Will increase over time. Bulk JSONs are streamed, not cached.
* Docker — **only** to run the end-to-end test database (`npm run e2e:db:up`). Nothing else in this project uses it, and you do not need it to develop. See [docs/testing.md](docs/testing.md).

## Installation

```bash
composer setup
```

Runs: `composer install` → copy `.env.example` → `key:generate` → `migrate` → `npm install` → `npm run build`.

After setup, configure `.env` with your database credentials, `APP_URL`, and `APP_CONTACT`. Then create the storage symlinks so public disks (set icons, symbols, art crops, card images) are accessible from the web:

```bash
php artisan storage:link
```

## Database seeding

Seeding requires Scryfall data to be present in the database. Run the full Scryfall sync first:

```bash
php artisan scryfall:update
```

Then seed containers and card stacks:

```bash
php artisan db:seed
```

The seeder creates the test user, wipes existing containers and card stacks for the first user, creates 10 sample containers, and distributes 60 random cards across them.

The end-to-end suite uses a different one — `E2ESeeder`, a fixed fixture that needs no Scryfall sync. It is run for you by `npm run e2e`; see [docs/testing.md](docs/testing.md).

## Documentation

In-depth docs live under `docs/`:

* **[Development](docs/development.md)** — `composer dev`, NPM commands, IDE setup (Prettier / ESLint / Stylelint).
* **[Testing](docs/testing.md)** — the four suites: `composer test` (Local / SQLite), `composer test:mysql` (Staging / MariaDB), `npm run test` (Vitest), `npm run e2e` (Playwright). Prerequisites and how to add new tests.
* **[Artisan commands](docs/artisan-commands.md)** — every project-specific artisan command: the Scryfall sync flow (shadow-table swap), per-step commands, image GC, FK repair, scheduled tasks.
* **[GitHub Actions](docs/github-actions.md)** — CI pipeline, staging deploy (manual), production deploy (manual, reviewer-gated), versioning, end-to-end release flow.
* **[Makefile shortcuts](docs/makefile.md)** — `make logs-staging` / `make logs-prod` for pulling server logs locally.

LLM-targeted architectural notes live in [`CLAUDE.md`](CLAUDE.md).

## License

`cantrip.me` is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## A note on AI usage

`cantrip.me` contains code that was written by a coding assistant, following strict guidelines on how to structure and architect the code. Every part that was not authored by a human has been reviewed and tested by a human.
