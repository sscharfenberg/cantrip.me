# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

cantrip.me — a Magic: The Gathering card collection manager. Laravel 13 + Vue 3 + Inertia.js.

## Commands

```bash
# Development
composer dev          # Parallel: artisan serve + queue:listen + pail + npm run dev

# Frontend
npm run dev           # Vite dev server
npm run build         # lint + type-check + Vite build + icon processing
npm run lint          # ESLint + Stylelint with auto-fix
npm run format        # Prettier
npm run type-check    # vue-tsc --build
npm run icons         # Process SVG icons

# End-to-end test database (NOT for local dev — see docs/testing.md)
npm run e2e:db:up            # throwaway MariaDB on :3307, waits until healthy
npm run e2e:db:down          # stop it; tmpfs storage means everything is discarded

# Frontend tests (Vitest)
npm run test                 # run the whole suite once
npm run test:watch           # watch mode
npm run test:coverage        # with v8 coverage report (.ts only — see vitest.config.ts)
npm run test -- harness      # filter by file path substring
npm run test -- -t "echoes"  # filter by test name

# End-to-end tests (Playwright) — drives the real app in Chromium, local only
npm run e2e                  # whole suite; starts the DB container + app server itself
npm run e2e -- --grep Deck   # filter by test name
npm run e2e:ui               # interactive runner
npm run e2e:report           # last run's HTML report (traces, screenshots)

# Backend
composer test                              # phpunit against SQLite (fast, local)
composer test -- --filter=DeckTest         # filtered (note the `--` separator)
composer test:mysql                        # same suite against MariaDB — run on staging
composer test:mysql -- --filter=DeckTest   # filtered on staging
php artisan tinker

# Full setup from scratch
composer setup        # composer install + .env.production + key + migrate + npm install + build
```

Requires Node 26.1+ and npm 11.13+.

## Architecture

**Stack:** Laravel 13 / PHP 8.4 · Vue 3 + TypeScript · Inertia.js · Vite · SCSS · Vue-i18n (de/en) · Laravel Fortify (auth + 2FA TOTP)

**How Inertia connects backend and frontend:**
- Laravel routes return `Inertia::render('Auth/Login', $props)` from controllers
- The component name maps directly to `resources/app/pages/Auth/Login.vue`
- Global shared props (csrfToken, auth, locale, features, flash) are injected in `HandleInertiaRequests.php` — no need to pass them explicitly in controllers
- Frontend navigation uses `router.visit(url)` or Inertia `<Link>` — never full-page reloads

**Frontend layout** (`resources/app/`):
- `pages/` — one Vue component per route, organized by auth state (`Auth/`, `Guest/`, `User/`)
- `components/` — reusable UI organized by type: `Form/`, `UI/`, `Layout/`, `Modal/`, `Popover/`, `Landmarks/`
- `composables/` — Vue 3 composables for stateful logic (`useLogin`, `useTwoFactorAuth`, `usePasswordEntropy`)
- `lang/` — i18n JSON files (`de.json`, `en.json`); default locale is `de`
- `styles/` — global SCSS; component styles go in `<style scoped>` inside `.vue` files

**Backend layout** (`app/`):
- `Http/Controllers/Auth/` — auth controllers (login, register, password reset, email verification)
- `Http/Controllers/User/` — authenticated user controllers (dashboard, password confirm)
- `Http/Middleware/HandleInertiaRequests.php` — defines all shared Inertia props
- `Http/Responses/` — custom Fortify response handlers
- `Models/User.php` — uses UUID primary key, `TwoFactorAuthenticatable`, locale preference

**Database:** MariaDB. Session, queue, and cache all use the `database` driver.

**Deck card references (dual-ID pattern):**
Every `deck_cards` row stores two card references:
- `oracle_card_id` — logical card identity for rules enforcement (singleton, color identity, legality)
- `default_card_id` — specific printing for display (card image), auto-picked as newest printing at creation
- **There is no `commanders` table** — it was removed, along with the `decks.companion_*` columns. Commanders, partners, signature spells and companions are ordinary `deck_cards` rows: `zone` (`App\Enums\DeckZone` — main/side/maybe/command/companion) places the row, and the nullable `role` (`App\Enums\DeckCardRole` — commander/partner/signature_spell/companion) tags the special slot. `unique(deck_id, role)` makes a second commander (or partner, signature spell, companion) impossible at the schema level; MySQL UNIQUE permits multiple NULLs, so ordinary mainboard rows are unaffected.
- Because the printing lives in the same row, changing a commander's printing is a plain `default_card_id` UPDATE.
- `CommandZoneService` (not `CommanderService`) owns all command zone search and validation logic

**Deck ↔ collection integration (modes A/B/C):**
Each deck-owner pair resolves to one of three modes that drive UI gating and data shape on the deck show page:
- **Mode A — silent.** Per-card badges, "Assign physical copy", "add all cards to collection", claim flow are all hidden.
- **Mode B — implicit tracking.** Coverage is *inferred* from `decks.container_id` (the deck's deckbox): cards in that container count as covered, others as elsewhere/missing. Silent at the badge layer if the deck has no `container_id` set.
- **Mode C — explicit tracking.** Per-card "claimed_for_this_deck / available / claimed_by_other_deck / wrong_printing / not_owned" badges render.
- Mode is chosen explicitly by the user via the deck-header collection-mode popover (PATCH `/decks/{deck}/collection-mode`); it's no longer inferred from pivot-row presence or stack count.
- Global master switch `User.collection_integration_enabled` overrides every deck to effective mode A while off — the per-deck `collection_mode` column is preserved so flipping the switch back on restores prior choices. The deck-header badge is hidden entirely while the switch is off.

**Service-of-record map:**
- `DeckCollectionStatusService` — read-side: resolves `effectiveMode`, ships `statusForDeck` (mode C) or `implicitStatusForDeck` (mode B) to the controller.
- `DeckCollectionModeService` — write-side: single `setMode($deck, $mode)` method handles every transition. C → B/A cascade-deletes the deck's `deck_card_card_stack` pivot rows in the same transaction as the column write; other transitions are pure column writes. No-op when already in the requested mode.
- `DeckCardAssignmentService` — single-stack replace on a deck card (per-card "Assign physical copy" picker). Atomic detach + oversize-split via `CardStackService::splitStack` + attach.
- `CardStackClaimService` — collection-side: `bulkClaimsForStacks` for the "Reserved for [deck]" badges, `unclaimAll` for the row-actions / edit-form unclaim button.
- `DeckFinalizeService` — persists submissions from the BulkClaim page (`/decks/{deck}/bulk-claim`, mode C, owner-only). Writes pivot rows, swaps `deck_cards.default_card_id` when the user picks an alternate printing, auto-splits deck cards on partial coverage, mints stacks for "bought new" rows, and sets `decks.container_id` when one was picked. Deck state transitions (planned/built/archived) are decoupled and go through `/decks/{deck}/state`, not this service.

**Lifecycle guards:** a claimed stack cannot change container — `UpdateCardStackRequest` and `MoveSelectedCardStacksRequest` 422 with `collection.errors.cannot_move_claimed_stack`. The intended UX is: user lands on the stack edit page from the 422, sees the "Reserved for [deck]" badge, clicks "Unclaim" inline, retries the move. Pivot rows cascade-delete on either deck deletion or stack deletion (DB FKs).

## Key Conventions

**Versioning:**
- Single source of truth: `package.json#version`. `config/app.php` reads it directly (`json_decode(file_get_contents(base_path('package.json')))->version`) — frozen into `bootstrap/cache/config.php` by `php artisan config:cache` at deploy time, so no per-request reads.
- **No** `APP_VERSION` in `.env` — it isn't consumed anywhere.
- Release flow: bump `package.json` version (when user-visible) → commit + push → manually dispatch **Deploy to production** in the Actions tab → approve at the reviewer gate. No tags involved. Workflows documented in `docs/github-actions.md`.

**Forms and API calls:**
- Form submission uses `fetch()` with JSON (not Inertia form helpers or traditional HTML forms) for flows that need fine-grained control (e.g. 2FA challenge)
- Validation errors come back as 422 with `{ errors: { field: [messages] } }`; map to flat `Record<string, string>` in composables
- Fortify password confirmation gate returns **423** when the session confirmation has expired

**Composables:**
- Shared reactive state is declared _outside_ the composable function (module-level `ref`s) so all consumers share the same instance — see `useTwoFactorAuth.ts`
- Return type exported as a named type (e.g. `UseTwoFactorAuthReturn`)

**Deck card views:**
- `pages/Deck/Cards/CardViewText.vue` and `pages/Deck/Cards/CardViewImage.vue` render the same deck cards in two layouts; the user picks per-deck. They are sibling views, not a hierarchy.
- When changing one, always check whether the change applies to the other and keep them in sync (props, classes that the rest of the app targets, integration with shared composables like `useRecentlyAdded`, etc.). Asymmetry is fine when intentional (e.g. drag-drop only in text view) — but make it deliberate, not accidental.

**Styling:**
- BEM naming: `.block__element--modifier`
- CSS property order is enforced by Stylelint (display → dimensions → visual → text → animation)
- SCSS abstracts (mixins, variables) live in `styles/abstracts/` and are aliased as `Abstracts`

**Imports:**
- Path aliases: `Components/`, `Composables/`, `Assets/`, `Abstracts/`, `@` (→ `resources/app`)
- ESLint enforces sorted import groups: builtin → external → internal (aliased) → parent → sibling
- Use type-only imports (`import type`) where no runtime value is needed

**i18n:**
- All user-visible strings go in `resources/app/lang/de.json` and `resources/app/lang/en.json`
- Keys mirror the component hierarchy: `pages.dashboard.two-factor.recovery_codes.show`
- Use `$t('key')` in templates; `useI18n()` in composables

**Icons:**
- SVG icons live in `resources/app/assets/icons/`; processed via `npm run icons`
- Rendered via `<Icon name="key" :size="3" />`

**Testing:**

Two testsuites are configured in `phpunit.xml`, each driven by its own composer script. They split along DB-engine lines:

- **`composer test`** runs the **`Local`** suite against in-memory SQLite. Covers `tests/Unit` + `tests/Feature` *excluding* `tests/Feature/Services`. Fast, no DB setup, write-heavy fixtures via `RefreshDatabase` are safe because the SQLite in-memory connection migrates once per process and wraps each test in a transaction.
- **`composer test:mysql`** runs the **`Staging`** suite against MariaDB. Covers `tests/Feature/Services` only — read-only tests that exercise `REGEXP` color-identity filtering and assert on real Scryfall data. Must run on a host with `DB_CONNECTION=mysql`, `DB_DATABASE=mbos`, and a populated Scryfall dataset (`php artisan scryfall:update` first). In practice: only runs on staging.
- Pass PHPUnit flags after `--`: `composer test:mysql -- --filter=DeckServiceTest`
- See `README.md` § "Backend commands" for detailed notes on the prerequisites.

**⚠️ Why the suites are physically split, not just gated by skips.** `RefreshDatabase` runs `migrate:fresh` on any non-`:memory:` connection — dropping every table including the Scryfall dataset and live user/deck data. The `--testsuite=Staging` filter on `test:mysql` ensures PHPUnit literally never discovers the `RefreshDatabase` tests, so they cannot reach a live MariaDB connection even if a future test forgets to add a defensive skip. The skip-guards in those tests (e.g. `DeckFinalizeServiceTest`, `DeckCollectionStatusServiceTest`, `DeckCardCardStackPivotTest`, `DeckFinalizeControllerTest`, `CollectionIntegrationToggleTest`) remain as belt-and-suspenders if someone runs PHPUnit without a `--testsuite` flag.

When adding new tests:
- If the test reads real Scryfall data and never writes → put it in `tests/Feature/Services/` and self-skip on `getDriverName() !== 'mysql'`.
- If the test uses `RefreshDatabase` (or any other write-heavy fixture) → put it elsewhere under `tests/Feature/` and self-skip on `getDriverName() === 'mysql'`.

**Frontend testing (Vitest):**

- Specs live in `__tests__/` folders **next to the code they cover** (`resources/app/utils/__tests__/colorIdentity.spec.ts`), named `*.spec.ts`. `tests/` at the repo root stays PHP-only.
- `vitest.config.ts` is deliberately separate from `vite.config.ts` — the build config's `laravel-vite-plugin`, image optimizer and devtools plugins are all wrong under test. What must not drift between the two (the alias map) lives in `resources/build/aliases.ts` and is imported by both; the Vue compiler options are mirrored by hand. Adding an alias means touching `resources/build/aliases.ts` **and** `tsconfig.json#compilerOptions.paths`.
- No test globals: import `describe`/`it`/`expect`/`vi` from `"vitest"` explicitly, so ESLint and `tsconfig.json` need no test-specific config.
- `resources/app/test/setup.ts` runs before every spec file. It patches the jsdom gaps this app trips over — the two observer APIs, `Element.scrollIntoView`, `matchMedia`, `AnimationEvent`, the `<dialog>` and popover methods, and an in-memory `localStorage`/`sessionStorage` (Node 26 ships a *disabled* `localStorage` global that shadows jsdom's) — and installs the Vue Test Utils defaults: a fresh i18n instance per test, a `v-tooltip` stub, `enableAutoUnmount`, and a reset of the Inertia doubles.
- i18n defaults to **key echo** — with no messages registered `$t("pages.login.title")` renders the key itself, so specs assert on keys and rewording `lang/de.json` can't turn tests red. When a test is *about* translated output — anything interpolating a value needs this, or the interpolation goes unverified — call `setTestMessages({ de: {...} })` from `resources/app/test/i18n.ts`. Do **not** pass a second `createTestI18n()` through `global: { plugins: [...] }`: Vue Test Utils appends to `config.global.plugins`, so vue-i18n installs twice and logs seven "already registered" warnings per mount.
- `resources/app/test/__tests__/harness.spec.ts` covers the harness itself. If it fails, fix the infrastructure before reading any other failure.

The shared test kit in `resources/app/test/`:

| Module | Use it for |
| --- | --- |
| `i18n.ts` | Key echo by default. `setTestMessages({ de: {...} })` registers real messages on the instance the test already has; `setTestLocale("en")` switches locale. `createTestI18n()` is for `withSetup`, which builds its own app. |
| `inertia.ts` | Doubles for `usePage`, `router`, `Head`, `Link`, `Form`, `useForm`. `setPageProps({...})` sets the shared props; `routerMock` / `formMocks` are the assertion surface. Both `pageProps` and the form doubles are **reactive**, because the real ones are. |
| `http.ts` | `installFetchMock()` — a `fetch` double returning real `Response` objects. Routes match a whole path (a query string may follow), newest registration wins, and `hang()` models abort semantics. |
| `observers.ts` | Controllable `IntersectionObserver` / `ResizeObserver`; `resizeObservers.at(-1).trigger({ inlineSize })` drives the callback. `trigger()` throws on a disconnected or unobserved instance rather than firing into the void. |
| `withSetup.ts` | Run a composable inside a component instance (needed for `provide`/`inject` and lifecycle hooks). Auto-unmounted after each test. |
| `factories/deckCard.ts` | `makeDeckCard` / `makeCommander` / `makeCompanion` / `makeCategory`. |

Conventions worth keeping:

- Any spec touching `@inertiajs/vue3` needs `vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());` at the top. The mock replaces the module wholesale, so a missing export is an import-time crash.
- Components rendered through `Modal.vue` **teleport into `<body>`** — assert through `document`, not through the wrapper.
- After a submit that fires a request, `await flushPromises()`; `trigger()` only awaits Vue's render queue.
- **Choose fixtures where the branches disagree.** Repeatedly during this rollout a spec passed against a deliberately broken implementation because every case in an `it.each` mapped to the same result. Mutate the source and confirm the suite goes red before trusting a new test.

**End-to-end testing (Playwright):**

- Specs live under `tests/e2e/`, split `guest/` (no session) and `app/` (signed in). **By directory, not by clearing cookies per test** — it is the only arrangement in which a stray `storageState` cannot make an auth-gate test pass by accident.
- `npm run e2e` needs nothing running first: global setup starts the `docker-compose.e2e.yml` MariaDB if it is down, rebuilds the bundle **and the icon sprite**, migrates fresh and seeds; Playwright then starts `artisan serve` on port 8101.
- The fixture is `database/seeders/E2ESeeder` — fixed ids, fixed names, fixed timestamps, no factories. `DatabaseSeeder` is unusable here: `DeckSeeder` pins printings by `(set code, collector number)` and so needs a full `scryfall:update`.
- **Belongs here only if Vitest structurally cannot answer it** — real layout, real navigation, real database, real CSP. Anything provable with a mounted component and a mocked `fetch` is forty times cheaper in Vitest.
- The three traps that cost the most to diagnose — a stale `public/hot` blanking every asset, a missing icon sprite making every icon-only control unclickable, and a cached config beating the environment overrides — are handled in `tests/e2e/support/environment.ts` and explained in `docs/testing.md`.
- Not in CI, by decision: a browser failure wants a trace and a one-spec re-run, which a CI round trip does not give cheaply.

**Scryfall data sync** (background commands, not part of normal dev):
- `php artisan scryfall:update` orchestrates the full sync via the shadow-table flow (see "Shadow-table architecture" below).
- Flow inside `scryfall:update`: `cleanup leftover shadows → createLike all shadow tables → bulk → sets → symbols → oracle → oracle-tags → default_cards → rulings → images → resolve-paths → restore FK constraints on shadow → validate FK orphans → atomic multi-table RENAME swap → drop retired → cache`. Bulk runs first so `bulk_data__shadow` populates the URIs that oracle/default_cards/rulings then read.
- Each sub-command supports `--target=live|shadow` (default `live`). Standalone `php artisan scryfall:sets` etc. behaves exactly as before; `UpdateEverything` invokes them with `--target=shadow`.
- **Bulk format: gzipped JSON Lines.** Scryfall serves each bulk as `.jsonl.gz` (`content-type: application/gzip`) — one card/ruling object per line. The plain `.json` array files it used to publish now 404, and the `/bulk-data` catalog renamed `size` → `compressed_size` and `download_uri` → `jsonl_download_uri` (unannounced; it broke the nightly run on 2026-07-29). `bulk_data.size` therefore holds the *compressed* size — Scryfall no longer publishes the uncompressed one — and `bulk_data.download_uri` holds `jsonl_download_uri`. `BulkdataService` validates the expected field set up front so the next such rename fails with a named error instead of an `Undefined array key` fatal.
- Bulk files (~475 MB gzipped total per run — `all_cards` ~372 MB + `default_cards` 73 MB + `oracle_cards` 23 MB + `rulings` 5 MB) are streamed directly from Scryfall via `ScryfallService::streamJsonl()` — the single reader all four bulk imports share. It inflates chunk-by-chunk with `inflate_add()` and decodes one line at a time, so peak memory stays in the tens of MB. No on-disk caching in any environment; every run re-fetches. Tradeoff: a mid-stream abort means the next run re-downloads from Scryfall.
- **Why the reader inflates by hand instead of using `compress.zlib://`:** completeness. The wrapper reads a truncated gzip as a clean EOF, so a connection dropped exactly on a line boundary would import a partial dataset and report success — the one failure mode the shadow-table flow exists to prevent. Inflating manually lets us require `ZLIB_STREAM_END`, which zlib only reports after validating gzip's trailing CRC32 and length over the whole payload. An undecodable line aborts too. Both are covered by `ScryfallServiceStreamTest`.
- `scryfall:default_cards` does a single-pass walk of the bulk file, inserting `default_cards` rows as it goes and buffering `all_parts` printing-pair edges (with the related card's display name) in memory. The relations buffer is flushed once at end-of-walk in 5 000-row chunks. There is no separate `scryfall:relations` command. Edges are keyed at the printing layer (not oracle) so the deck view can show the *matching* token printing for the deck's chosen card printing — see `App\Enums\Scryfall\ScryfallRelatedComponent` for the four edge types (token, meld_part, meld_result, combo_piece).
- **Orphan re-targeting in `default_card_relations`:** Scryfall's `all_parts` emits ~2 000 edges per bulk where `id` references a printing not in default_cards (foreign-language UUID, excluded layout, etc.). `flushRelationsBuffer()` builds a `searchable_name → default_cards.id` lookup (joining oracle_cards onto default_cards) and rewrites orphan edges to point at a valid printing of the same oracle. The previous live flow silently dropped these via FK-violation-as-IGNORE; the shadow flow has no FKs during build, so the rewrite is explicit. Surfaced in the import output as `re-targeted N orphan default_card_relations edges to default printings of the same oracle.`
- `scryfall:rulings` is its own command (after `default_cards`). Pre-loads existing `oracle_cards.id` into a memory hash and silently skips rulings whose `oracle_id` doesn't match a known card (tokens, etc.). Bulk-inserts in 500-row batches via `Ruling::insert()`. The `source` column is enum-cast to `App\Enums\Scryfall\ScryfallRulingSource` (`wotc` | `scryfall`); unknown sources are also skipped via `tryFrom`. Used by the card preview modal to render rulings sorted by `published_at` descending (newest first).
- `oracle_cards` carries a `produced_mana` column (concatenated single letters: WUBRGC) populated from Scryfall's top-level `produced_mana` array — it's an oracle-level property aggregated across faces, NOT a per-face property, even for transform/MDFC cards where only one face produces mana (verified against Bitterblossom / Bala Ged Recovery / Westvale Abbey). Surfaced in the card preview modal as a "Produces" row via the existing `<mana-cost>` component.
- `scryfall:oracle-tags` is a one-stop sync for every `otag:` → `oracle_cards` column mapping. Strategy classes under `app/Services/Scryfall/OracleTagSyncs/` describe each mapping; the orchestrator (`OracleTagsService`) handles the paginated search, the ≥1s inter-request pacing (held across pages AND across multiple syncs in the same run), and the clear-then-apply transaction. Two strategies today: `BooleanOracleTagSync` (`mass-land-denial` → `oracle_cards.mld`, drives the bracket auto-suggest hint) and `FetchPatternOracleTagSync` (`fetchland` → `oracle_cards.fetch_pattern`, drives deck-aware fetchland resolution). Add a new tag by appending one more strategy to the orchestrator's constructor.
- `oracle_cards.fetch_pattern` stores the **kind** of fetch (deck-independent), not the colors it produces — those are resolved per deck on the frontend by walking the deck's other lands and unioning their `produced_mana`. Format: `basic` (any basic), `basic:<WUBRG>` (basic of specific types — Panoramas), `typed:<WUBRG>` (typed land basic-or-not — Onslaught/Mirage fetches), `any` (Urza's Cave). Without it, fetchlands would show 0 produced mana in the deck-stats donut and Karsten "have" calculation since Scryfall omits them from `produced_mana`.
- Image handling is split into three services with strict separation of concerns:
  - **Import** (`DefaultCardsService`, `OracleCardsService`) — stores raw Scryfall URLs in DB, no disk access
  - **Download** (`ImageDownloadService`) — downloads images to disk, no DB writes. Target-aware: queries `default_cards__shadow` joined to `sets__shadow` when invoked with `--target=shadow`, so newly imported cards (still carrying `https://…` URLs) get cached locally during the shadow build. Querying live during a shadow run would return zero rows after the first successful sync and leave new cards pointing at the Scryfall CDN.
  - **Resolve** (`ResolveImagePathsService`) — updates DB URLs to local paths, no downloads
- The final `scryfall:cache` step rebuilds the welcome-page stats cache (`welcome.scryfallStats`, stored with `Cache::forever`) so the next visitor doesn't pay the recursive `find` + `du` cost. Can also be run standalone if the cache table is wiped.
- `scryfall:gc-images` cleans up downloaded image files whose UUID is no longer in `default_cards` (Scryfall data corrections, foreign-language reshuffles, etc.). Defaults to dry-run; pass `--prune` to actually delete. Per-card stale-timestamp cleanup is handled inline by `ImageDownloadService::cleanupOldVersions()`; `gc-images` covers the second class of orphan (cards entirely removed from default_cards). `scryfall:update` runs the same scan (via `ImageOrphanScanner`) at the end as a dry-run and surfaces a hint with the `--prune` command if any orphans are found — actual deletion is always opt-in. Run `--prune` manually when the orphan count seems worth reclaiming.
- See `README.md` § "Artisan commands" for detailed documentation

**Shadow-table architecture (`scryfall:update`):**

The full update runs against `__shadow` build tables and atomically swaps them into live at the end. The site stays usable throughout — no maintenance mode, no downtime, no partial-state window.

- **Why:** the import strategy is truncate-then-insert. A mid-flight crash on the live tables leaves them empty/partial — every user-data FK (deck_cards.oracle_card_id, etc.) breaks until the next successful run. With shadow tables, the live tables are untouched until the atomic RENAME swaps in a fully-built dataset.
- **Tables in scope** (10): `sets`, `symbols`, `artists`, `bulk_data`, `oracle_cards`, `oracle_card_faces`, `legalities`, `default_cards`, `default_card_relations`, `rulings`. Single source of truth: `App\Services\Scryfall\Shadow\ShadowTableRegistry`.
- **Naming:** `<table>__shadow` during build, `<table>__retired` after the swap (dropped at end). Cleanup-on-startup drops both suffixes for any registered table — recovers from a crashed prior run automatically.
- **`CREATE TABLE LIKE` does NOT copy FK constraints.** Shadow tables are intentionally built without FK constraints — the orphan validator (below) checks integrity via LEFT JOINs instead, and the FK constraints get re-added to the live tables after the swap. This sidesteps a critical MariaDB quirk: when you rename a parent table, MariaDB **silently auto-rotates FK references in every dependent table** to point at the renamed-to name. So if the swap happened with FK constraints in place, every FK pointing at e.g. `sets` (including user-data FKs from `deck_cards`/`card_stacks`/etc.) would rotate to point at `sets__retired`, then become permanently broken when dropRetired drops that table.
- **Orphan validation before swap.** `ShadowValidationService::findOrphans()` LEFT JOINs every internal scryfall FK plus three user-data FKs (`deck_cards.{oracle_card_id, default_card_id}`, `card_stacks.default_card_id`) against the shadow build. Non-zero abort is rare in practice (Scryfall almost never removes cards) — it'd mean a Scryfall card any user references has gone missing from the new dataset. On abort, the shadow tables stay on disk for inspection; cleanup-on-startup drops them next run. Recovery is manual case-by-case (oracle-id merge → UPDATE the user FK; printing reshuffle → re-resolve to a different valid printing; true removal → DELETE + email user).
- **FK preservation around the swap.** Sequence: `captureForeignKeys()` queries `INFORMATION_SCHEMA.KEY_COLUMN_USAGE` for every FK whose `REFERENCED_TABLE_NAME` is one of the 10 swap tables (catches both internal FKs on the swap tables AND user-data FKs into them). `dropForeignKeys()` drops them all (FK_CHECKS=0). `swap()` does the atomic multi-table RENAME with no FK constraints to rotate. `dropRetired()` drops the `__retired` tables (no FK constraints attached now). `addForeignKeys()` re-adds the captured FKs — references resolve to the new live tables (which now own the names the FKs target). Constraint names + on_delete/on_update rules are preserved verbatim from capture.
- **Atomic swap:** single multi-table `RENAME TABLE` statement listing all 20 pairs (10 lives → __retired, 10 shadows → live). MariaDB executes the whole statement atomically — either every swap happens or none does. Runs after FK constraints have been dropped, so the auto-rotate bug doesn't apply.
- **Failure handling:** any throw before swap (build failure, validation abort, etc.) leaves live tables byte-for-byte untouched. Existing notification path (mail + Discord embed via `ScryfallUpdateFailedNotification`) fires from `UpdateEverything::dispatchFailureAlert()`. Site keeps serving the previous-good dataset throughout.
- **Standalone sub-commands** (`scryfall:sets`, `scryfall:oracle`, etc.) default to `--target=live` and behave like before — truncate live + insert. They support `--target=shadow` for the orchestrator's invocation, which skips truncate (orchestrator did createLike) and writes to `<table>__shadow` instead.
- **Eloquent vs query builder:** every shadow-mode insert path uses `DB::table($shadowTable)->insert(...)` rather than `Model::create()` / `Model::insert()`, because Eloquent's hardcoded `$table` property can't be parameterized. Side effects: model `$casts` (e.g. `BulkData.updated_at = 'datetime'`) are NOT applied — `Carbon::parse()->toDateTimeString()` is needed before insert for any datetime-cast column. UUIDs that previously came from the `HasUuids` trait need explicit `Str::uuid()` (currently relevant in `ArtistsService`, `SymbolsService`, `RulingsService`).
- **Foundation services** in `app/Services/Scryfall/Shadow/`: `ShadowTableRegistry` (single source of truth — table list + FK restorations + FK check definitions), `ShadowTableService` (cleanup / createLike / restoreForeignKeys / swap / dropRetired), `ShadowValidationService` (FK orphan checks). MariaDB-only — feature tests in `tests/Feature/Services/Shadow/` self-skip on SQLite.

## Staging Server as Dev Environment

Staging (`staging.cantrip.me`) is used as a remote dev server — same environment as prod, avoiding local Docker setup.

**The one exception is the end-to-end test database.** `docker-compose.e2e.yml` runs a throwaway MariaDB on port **3307** for the Playwright suite, because the deck-building core filters colour identity with `REGEXP` and SQLite cannot run it. It is not a development database and nothing in normal development touches it — dev still points at staging, as below. It listens on 3307 rather than 3306 precisely so the two can never be confused. See `docs/testing.md`.

**How it works:**
- Vite runs on staging at port 5173 (HTTP, internal only)
- Apache proxies `https://staging.cantrip.me:5174` → `http://127.0.0.1:5173`, handling TLS termination
- `VITE_SERVER_ORIGIN=https://staging.cantrip.me:5174` in staging `.env` tells Vite what URL to write to `public/hot`
- `cors: true` in `vite.config.ts` is required — different port means different origin
- Port 5174 is open in Hetzner firewall and added to `/etc/apache2/ports.conf`
- Apache vhost config: `/etc/apache2/sites-enabled/cantrip-staging-vite.conf`

**Running Vite on staging:**
```bash
ssh cantrip
screen -r vite        # reattach existing session
# or if not running:
screen -S vite
npm run dev
# Ctrl+A, D to detach
```

**Stopping Vite on staging (do this when not actively developing):**
```bash
ssh cantrip 'screen -X -S vite quit'   # one-shot kill of the screen + Vite
```
Why: port 5174 is publicly reachable through the Apache TLS proxy, and Vite's dev server has had path-traversal CVEs in the past. With `cors: true` set in `vite.config.ts`, anyone on the internet who finds the port can probe it. Killing the screen session when you're done dev'ing closes the attack surface; the next `npm run dev` reopens it. Just `Ctrl+C` inside the screen leaves an empty session attached — use `screen -X -S vite quit` (or `exit` after `Ctrl+C`) to fully reap it.

**PhpStorm deployment:** exclude `public/hot` — the staging server owns that file.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4+
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v11
- @inertiajs/vue3 (INERTIA_VUE) - v2
- vue (VUE) - v3
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `inertia-vue-development` — Develops Inertia.js v3 Vue client-side applications. Activates when creating Vue pages, forms, or navigation; using <Link>, <Form>, useForm, or router; working with deferred props, prefetching, or polling; or when user mentions Vue with Inertia, Vue pages, Vue forms, or Vue navigation.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan Commands

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`, `php artisan tinker --execute "..."`).
- Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Debugging

- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.
- To execute PHP code for debugging, run `php artisan tinker --execute "your code here"` directly.
- To read configuration values, read the config files directly or run `php artisan config:show [key]`.
- To inspect routes, run `php artisan route:list` directly.
- To check environment variables, read the `.env` file directly.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - `public function __construct(public GitHub $github) { }`
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<!-- Explicit Return Types and Method Params -->
```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
```

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scrolling (merging props + `WhenVisible`), lazy loading on scroll, polling, prefetching.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
