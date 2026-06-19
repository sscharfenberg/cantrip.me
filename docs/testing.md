# Testing

## Test suites at a glance

The PHPUnit configuration in `phpunit.xml` defines two physically separate testsuites that split along DB-engine lines. Each composer script targets exactly one suite, so running the wrong command on the wrong host can't ever drop a `RefreshDatabase` test onto the live MariaDB.

| Suite      | Directories                                                | DB driver            | Composer script        |
|------------|------------------------------------------------------------|----------------------|------------------------|
| `Local`    | `tests/Unit` + `tests/Feature` *(excl. `Feature/Services`)*| SQLite in-memory     | `composer test`        |
| `Staging`  | `tests/Feature/Services` only                              | MariaDB (`mbos`)     | `composer test:mysql`  |

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

## Adding new tests

* If the test reads real Scryfall data and never writes to the DB → put it in `tests/Feature/Services/` and self-skip on `getDriverName() !== 'mysql'`. It will run via `composer test:mysql` only.
* If the test uses `RefreshDatabase` (or any other write-heavy fixture) → put it elsewhere under `tests/Feature/` and self-skip on `getDriverName() === 'mysql'`. It will run via `composer test` only.

The skip-guards inside individual tests are belt-and-suspenders on top of the testsuite split — they protect against running raw `php artisan test` (no `--testsuite` flag) on a host with the mysql connection configured.
