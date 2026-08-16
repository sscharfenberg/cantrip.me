# Testing

## Test suites at a glance

| Suite               | What it covers                                              | Runs against              | Command                |
|---------------------|-------------------------------------------------------------|---------------------------|------------------------|
| PHPUnit `Local`     | `tests/Unit` + `tests/Feature` *(excl. `Feature/Services`)* | SQLite in-memory          | `composer test`        |
| PHPUnit `Staging`   | `tests/Feature/Services` only                                | MariaDB (`mbos`)          | `composer test:mysql`  |
| Vitest              | `resources/app/**/__tests__/*.spec.ts`                       | jsdom, no server          | `npm run test`         |
| Playwright          | `tests/e2e/**/*.spec.ts`                                     | Chromium + the real app   | `npm run e2e`          |

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

* **It listens on 3399 — not 3306, and pointedly not 3307.** `.env` points local dev at `127.0.0.1:3306`/`mbos`, so a matching port would let a stray `DB_HOST` reach the test database or let a test run wipe a real one. 3307 looks like the obvious alternative and is the worse trap: `~/.ssh/config` gives the `cantrip` host a `LocalForward 3307 localhost:3306`, so an open ssh session to staging owns that port and **what answers on it is staging's own database**. A run started while that tunnel was up would find something listening, believe the container was healthy, and aim `migrate:fresh` down the tunnel — with only the credentials in the way. `assertIsE2EDatabase()` in `tests/e2e/support/environment.ts` is the second line of defence: it compares MariaDB's `@@hostname` against the compose container id and aborts if they differ. It is also bound to loopback, because an open MariaDB on `cantrip_e2e`/`cantrip_e2e` is found by a subnet scan in seconds.
* **Its storage is `tmpfs`, with no volume.** Every run starts with `migrate:fresh`, so nothing in it is meant to outlive a run, and a persistent volume would let a half-finished run leave state the next one silently inherits. `npm run e2e:db:down` is a guaranteed reset — verified: 27 tables before, 0 after.

**Connecting to it by hand**, for poking at a failure:

```bash
docker exec -it cantrip-e2e-db-1 mariadb -ucantrip_e2e -pcantrip_e2e cantrip_e2e

# or run an artisan command against it
env DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3399 \
    DB_DATABASE=cantrip_e2e DB_USERNAME=cantrip_e2e DB_PASSWORD=cantrip_e2e \
    php artisan migrate --force
```

If an artisan command against it fails with a connection error that names the *wrong* database, run `php artisan config:clear` first — a cached `bootstrap/cache/config.php` beats real environment variables.

## `npm run e2e` — the Playwright suite

Drives the **real app** in a real Chromium: Laravel over the throwaway MariaDB above, migrated and seeded fresh at the start of every run.

```bash
npm run e2e                       # the whole suite
npm run e2e -- --grep "Deck"      # one slice
npm run e2e -- tests/e2e/guest    # one directory
npm run e2e:ui                    # the interactive runner, for debugging a failure
npm run e2e:report                # last run's HTML report, with traces and screenshots
```

Nothing needs to be running first. `globalSetup` starts the database container if it is down, rebuilds the bundle and the icon sprite, migrates fresh and seeds — then Playwright starts the app server itself on **port 8101**.

**It exists for the questions Vitest structurally cannot answer.** jsdom has no layout, no navigation, no Inertia server and no database, so "a page really boots with its assets", "auth genuinely gates this route" and "the deck query comes back from a real `REGEXP`" all live here rather than there. Anything that can be proven with a mounted component and a mocked `fetch` belongs in Vitest, which is roughly forty times faster per assertion.

**It is deliberately not in CI.** A browser failure wants a trace, a screenshot and a re-run of one spec, and a CI round trip gives you none of those cheaply. Putting it there later needs a MariaDB service container and an `npx playwright install --with-deps chromium` step; nothing in `playwright.config.ts` assumes it will not happen.

### Layout

```
tests/e2e/
├── support/
│   ├── environment.ts     ports, the server environment, the DB/asset/hot-file plumbing
│   ├── globalSetup.ts     container → assets → migrate → seed, in that order
│   ├── globalTeardown.ts  puts a stashed public/hot back
│   ├── auth.setup.ts      signs in once, parks the session for the `app` project
│   └── actions.ts         helpers shared across specs
├── guest/                 run with NO stored session
│   ├── smoke.spec.ts         the harness itself: server, CSS, bundle, sprite, auth gate
│   ├── auth.spec.ts          login, right and wrong
│   ├── logout.spec.ts        signs in for real, signs out, checks the gate came back
│   ├── register.spec.ts      a taken username, and a successful registration
│   └── public-pages.spec.ts  /about, /imprint, /privacy
└── app/                   run signed in as the seeded user
    ├── smoke.spec.ts         the parked session really belongs to the seeded account
    ├── decks.spec.ts         the deck list: format folders, colour pips, opening a deck
    ├── deck.spec.ts          command zone, categories, quick-add colour identity
    └── collection.spec.ts    stacks, container totals, filtering, container isolation
```

Guest and app specs are separated **by directory, not by clearing cookies per test**. It is the one arrangement in which a stray `storageState` cannot make an auth-gate test pass by accident — the failure mode that would leave the whole project green and worthless.

**Writes need a lane of their own.** `fullyParallel` splits tests *within* a file as well as across files, and every worker shares one database, so a test that writes can land on a test that reads at any moment. The convention is: reads use the Atraxa deck, the one write uses the partner deck, and it adds a card **no reader searches for** — because `DeckCardSearchService` drops cards the deck already holds at the format's per-card maximum, so adding a searched-for card would make an unrelated test find nothing about half the time. If you add a mutating spec, say in a comment which readers you checked.

### The fixture

`database/seeders/E2ESeeder`, **not** `DatabaseSeeder`: that one chains `DeckSeeder`, which pins every slot to a specific Scryfall printing and so needs a full `scryfall:update` behind it. `E2ESeeder` is fixed and committed — same ids, names and printings every run, because a browser test names the thing it clicks.

It seeds one verified user, a 22-card Scryfall snapshot, three containers with eight card stacks, and three decks:

| Deck | Format | Identity | There to make this answerable |
| --- | --- | --- | --- |
| Atraxa Superfriends | Commander | WUBG | a single commander; four colours; Lightning Bolt is *out* of identity |
| Yoshi and Rograkh | Commander | RW | a partner pair; Counterspell is *out* of identity, Lightning Bolt is in |
| Legacy Burn | Legacy | — | a format with **no** command zone; quantities above one; a real sideboard |

The two commander decks are the reason a card-search assertion can mean something. A search that ignored `color_identity` would return Lightning Bolt in both; one that returned nothing would fail both. Neither can pass by accident.

`database/seeders/fixtures/scryfall.json` is real Scryfall data — real ids, oracle text, prices and `color_identity` strings — because the app reasons about all of it, and a hand-written approximation would be a fixture that agrees with the tests rather than with Magic. To extend it, add a card name to `database/seeders/fixtures/extract-scryfall-fixture.php` and re-run that script **on staging** (the only host with a full dataset); the script's own docblock has the commands. Nothing at test time talks to staging.

**Card images 404**, deliberately. The snapshot keeps the real `card_image_*` paths, which point into `public/card-images` — a directory a developer machine does not have. Writing placeholders there would mean writing into a directory whose meaning differs per machine (on the servers it is a symlink into shared storage). Nothing asserts on artwork yet; a spec that needs it should generate the files from `environment.ts` rather than commit them.

### Three traps worth knowing before you touch the harness

* **`public/hot` silently blanks every asset.** The file is written by `npm run dev` and is *not* removed when that server stops, and while it exists `@vite` ignores the built manifest entirely. This repo has a sharper version of it than most: development happens on staging, whose `public/hot` names `https://staging.cantrip.me:5174`. `stashStaleHotFile()` parks a *stale* marker for the duration of a run and puts it back afterwards — a live dev server is left alone, since it serves assets perfectly well. Symptom when it goes wrong: every selector times out.
* **The icon sprite is not part of the Vite build.** `npm run icons` writes `storage/app/public/sprite.svg` and `app.blade.php` inlines it; `<Icon>` renders `<use href="#name">` against it. Without it nothing errors and the markup still validates — the icons are just invisible, and every icon-only control becomes unclickable. `buildAssets()` runs both steps for this reason, and `guest/smoke.spec.ts` asserts the sprite is on the page.
* **A cached config beats real environment variables.** The whole run is configured by overriding the environment rather than editing `.env`, so a stale `bootstrap/cache/config.php` would point `migrate:fresh` at the *development* database. `resetDatabase()` runs `config:clear` first, every time.

## Adding new tests

* If the test reads real Scryfall data and never writes to the DB → put it in `tests/Feature/Services/` and self-skip on `getDriverName() !== 'mysql'`. It will run via `composer test:mysql` only.
* If the test uses `RefreshDatabase` (or any other write-heavy fixture) → put it elsewhere under `tests/Feature/` and self-skip on `getDriverName() === 'mysql'`. It will run via `composer test` only.

The skip-guards inside individual tests are belt-and-suspenders on top of the testsuite split — they protect against running raw `php artisan test` (no `--testsuite` flag) on a host with the mysql connection configured.

For the browser suite:

* Put it in `tests/e2e/` only if it needs the browser to be a browser or the app to be the app — real layout, real navigation, a real session, a real database. Anything provable with a mounted component and a mocked `fetch` is about forty times cheaper in Vitest.
* `guest/` if it must have no session, `app/` if it must have one.
* **Mutate the source and confirm the test goes red before trusting it.** Every assertion in this suite was checked that way; several looked fine and proved nothing until the fixture was chosen so the two branches disagreed.
