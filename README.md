# cantrip.me

A Magic: The Gathering card collection manager with a focus on UX: Dark/Light mode. Multi-language. Accessibility first. Responsive. Fast.Instead of 

**Stack:** Laravel 13 / PHP 8.4 · Vue 3 + TypeScript · Inertia.js · Vite · SCSS · MariaDB · Vue-i18n (de/en) · Laravel Fortify (auth + 2FA TOTP)

## Requirements

* PHP 8.4+
* Composer
* Node 26.1+ / npm 11.13+
* MariaDB
* `~28 GB` of harddisk space for cached Scryfall images (art crops + card images). Will increase over time. Bulk JSONs are streamed, not cached.

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

## Documentation

In-depth docs live under `docs/`:

* **[Development](docs/development.md)** — `composer dev`, NPM commands, IDE setup (Prettier / ESLint / Stylelint).
* **[Testing](docs/testing.md)** — `composer test` (Local / SQLite) vs. `composer test:mysql` (Staging / MariaDB), prerequisites, how to add new tests.
* **[Artisan commands](docs/artisan-commands.md)** — every project-specific artisan command: the Scryfall sync flow (shadow-table swap), per-step commands, image GC, FK repair, scheduled tasks.
* **[GitHub Actions](docs/github-actions.md)** — CI pipeline, staging deploy (manual), production deploy (tag-driven, reviewer-gated), versioning, end-to-end release flow.
* **[Makefile shortcuts](docs/makefile.md)** — `make logs-staging` / `make logs-prod` for pulling server logs locally.

LLM-targeted architectural notes live in [`CLAUDE.md`](CLAUDE.md).

## License

`cantrip.me` is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## A note on AI usage

`cantrip.me` contains code that was written by a coding assistant, following strict guidelines on how to structure and architect the code. Every part that was not authored by a human has been reviewed and tested by a human.
