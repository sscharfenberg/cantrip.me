# Testing

## Test suites at a glance

| Suite               | What it covers                                              | Runs against              | Command                |
|---------------------|-------------------------------------------------------------|---------------------------|------------------------|
| PHPUnit `Local`     | `tests/Unit` + `tests/Feature` *(excl. `Feature/Services`)* | SQLite in-memory          | `composer test`        |
| PHPUnit `Staging`   | `tests/Feature/Services` only                                | MariaDB (`mbos`)          | `composer test:mysql`  |
| Vitest              | `resources/app/**/__tests__/*.spec.ts`                       | jsdom, no server          | `npm run test`         |

The two PHPUnit testsuites in `phpunit.xml` are physically separate and split along DB-engine lines. Each composer script targets exactly one suite, so running the wrong command on the wrong host can't ever drop a `RefreshDatabase` test onto the live MariaDB.

## `composer test`

Runs the **`Local`** suite against the default SQLite in-memory driver. Fast, local, no DB setup required. Covers all unit tests and the write-heavy feature tests that use `RefreshDatabase` — those need a fresh schema each run, which is cheap on `:memory:` SQLite and catastrophic on a real MariaDB.

```bash
composer test                                         # Local suite
composer test -- --filter=DeckFinalizeServiceTest     # filtered (note the `--`)
```

## `composer test:mysql`

Runs the **`Staging`** suite against MariaDB. Use this on staging (or any server with a populated `mbos` database) to exercise the read-only feature tests that require real Scryfall data and MariaDB-only SQL (`REGEXP` color-identity filters, accent-folding collations). The `--testsuite=Staging` filter is built into the composer script, so PHPUnit never even discovers the `Local` suite — `RefreshDatabase` cannot reach a live connection by accident.

```bash
# On staging
composer test:mysql                                   # Staging suite
composer test:mysql -- --filter=DeckServiceTest       # filtered (note the `--`)
```

**Prerequisites:**

* `DB_CONNECTION=mysql` and `DB_DATABASE=mbos` must point at a real MariaDB instance. The composer script injects these as inline shell environment variables before `php artisan test` so they beat `phpunit.xml`'s non-forced `<env>` tags — do not override them on the CLI.
* The database must contain a full Scryfall import. Run `php artisan scryfall:update` first — the MariaDB-only tests assert on bedrock cards (Sol Ring, Lightning Bolt, Atraxa, Yoshimaru, etc.) that only exist after the sync.
* `phpunit.xml` must exist on the target machine (it is not `.dist`-suffixed, so PhpStorm deployment must not exclude it).
* If you recently ran `php artisan config:cache`, clear it first with `php artisan config:clear` — the test scripts no longer do this implicitly (it conflicts with forwarding `--filter` through the script chain).

## The end-to-end database (`docker-compose.e2e.yml`)

**This container is for the end-to-end suite only. It is not a development database, and local development does not use it** — that still happens against staging, as described in `CLAUDE.md` under "Staging Server as Dev Environment".

```bash
npm run e2e:db:up      # start it and wait until it answers
npm run e2e:db:down    # stop it and discard everything in it
```

**Why a real MariaDB and not SQLite.** The end-to-end suite drives the real app, and the app's deck-building core is MariaDB-only: `DeckCardSearchService` and `CommandZoneService` filter colour identity with `REGEXP`, which SQLite cannot run at all. That is the same engine split the `Local` / `Staging` suites above already encode. An end-to-end run on SQLite could only cover the flows that avoid card search — which is most of the reason to have one.

**What the container is.** MariaDB 11.8, matching staging and production (11.8.6), with `utf8mb4` / `utf8mb4_unicode_ci` as the server default so the schema it creates matches theirs rather than "matches except the tables nobody thought to pin".

**Two design points worth knowing before you change them:**

* **It listens on 3307, not 3306.** `.env` points local dev at `127.0.0.1:3306`/`mbos`. On a matching port, a stray `DB_HOST` or a forgotten tunnel could let the app talk to the test database — or let a test run wipe a real one. A different port makes both mistakes structurally impossible rather than merely unlikely. It is also bound to loopback, because an open MariaDB on `cantrip_e2e`/`cantrip_e2e` is found by a subnet scan in seconds.
* **Its storage is `tmpfs`, with no volume.** Every run starts with `migrate:fresh`, so nothing in it is meant to outlive a run, and a persistent volume would let a half-finished run leave state the next one silently inherits. `npm run e2e:db:down` is a guaranteed reset — verified: 27 tables before, 0 after.

**Connecting to it by hand**, for poking at a failure:

```bash
docker exec -it cantrip-e2e-db-1 mariadb -ucantrip_e2e -pcantrip_e2e cantrip_e2e

# or run an artisan command against it
env DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 \
    DB_DATABASE=cantrip_e2e DB_USERNAME=cantrip_e2e DB_PASSWORD=cantrip_e2e \
    php artisan migrate --force
```

If an artisan command against it fails with a connection error that names the *wrong* database, run `php artisan config:clear` first — a cached `bootstrap/cache/config.php` beats real environment variables.

## Adding new tests

* If the test reads real Scryfall data and never writes to the DB → put it in `tests/Feature/Services/` and self-skip on `getDriverName() !== 'mysql'`. It will run via `composer test:mysql` only.
* If the test uses `RefreshDatabase` (or any other write-heavy fixture) → put it elsewhere under `tests/Feature/` and self-skip on `getDriverName() === 'mysql'`. It will run via `composer test` only.

The skip-guards inside individual tests are belt-and-suspenders on top of the testsuite split — they protect against running raw `php artisan test` (no `--testsuite` flag) on a host with the mysql connection configured.
