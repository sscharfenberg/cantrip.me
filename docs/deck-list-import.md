# Deck list import — plan

Status: implemented on branch `feature/deck-list-import`, 2026-10-04. See "As built" at the end for where the implementation departs from the plan.

Create a new deck by pasting a deck list copied from another site (Moxfield, Archidekt, Draftsim, MTGA, MTGO, …) into a textarea. Complements the CSV import (`/decks/import`), which stays as it is.

## Scope

- **New decks only.** The import creates the deck; importing into an existing deck is out of scope for now.
- **Name and format only** on the first page. Description, bracket, visibility, state and hero image keep their defaults and are edited on the deck page afterwards.
- **Its own page**, not a mode of the CSV import page. The CSV import is identifier-driven (Scryfall id, set + collector number); this one is name-driven and needs a review step the CSV flow does not.

- **Custom categories are imported** (§1, "Categories"); the maybeboard is not.

Not in v1: foil and condition markers (`*F*`, `*E*`), Archidekt `^tag^` labels, any link to the collection (claims, stacks). These are parsed and dropped, not rejected.

## Flow

```
/decks/import-list            name + format + textarea
        │  POST /api/decks/import-list/parse   (writes nothing)
        ▼
review (same page, client-side state)
  - unresolved lines → pick a suggestion, search, or exclude
  - exclude any line
  - zone per section (only where the parser guessed)
  - command zone + "change command zone" (commander / oathbreaker formats)
        │  POST /decks/import-list              (one transaction)
        ▼
/decks/{deck}
```

Nothing is written before the final POST. Leaving the review page abandons the import — there is no draft to clean up.

## 1. Parser

`App\Services\DeckList\DeckListParser` — a pure function from text to parsed lines. No database access, so it is unit-tested against fixture files without fixtures in the DB.

Every line is classified as one of three things:

**Card line** — `[qty][x] Name [(SET) [number]] [noise]`

| Example | Parsed |
| --- | --- |
| `1 Sol Ring` | 1 × "Sol Ring" |
| `1x Sol Ring` | same |
| `Sol Ring` | quantity defaults to 1 |
| `1 Sol Ring (C21) 263` | + set `c21`, number `263` |
| `1 Sol Ring (c21)` | + set only |
| `1 Sol Ring (C21) 263 *F*` | foil marker dropped |
| `1x Sol Ring (c21) 263 [Ramp] ^Have^` | + category `Ramp`; `^tag^` dropped |
| `1 Sol Ring (C21) 263 #Ramp` | + category `Ramp` (Moxfield tag; `#!Ramp` global tags too) |
| `1 Fire // Ice` | double-faced / split name kept whole |

Collector numbers are free-form tokens (`263`, `263a`, `★12`, `M19-123`). Set codes are lower-cased for lookup.

**Section header** — sets the zone, or the category, for the card lines below it.

| Header | Effect |
| --- | --- |
| `Commander`, `Commanders` | command zone candidates (see §4) |
| `Companion` | companion |
| `Sideboard` | side |
| `Maybeboard`, `Considering` | **dropped** — the lines below are skipped; the review page reports how many |
| `Deck`, `Main`, `Mainboard` | main |
| card types — `Creatures`, `Instants`, `Lands`, … | main, no category (the deck page groups by type itself) |
| anything else | main, category = the header text |

A line is a header only when it is marked as one: a known header word above, a leading `//`, a trailing `:` or `(n)`, or a divider line directly below it (cantrip.me's own print layout). An unmarked line without a quantity is a card line with quantity 1 — if it is really a stray title it fails to resolve and the user excludes it. Guessing the other way would silently turn `Sol Ring` into a category.

**Noise** — blank lines, divider lines (`---`, `===`), comments (`#`-only lines, `//` lines that are not headers), and anything else that does not parse. Unparseable non-blank lines are returned as unresolved, not silently dropped, so the user sees them on the review page.

**Blank lines without headers.** MTGO and MTGA lists put the sideboard after a blank line with no header. When a list has *no zone headers at all* and exactly two blocks separated by a blank line, the second block is tentatively `side` and the section is flagged `guessed` — the review page shows a zone dropdown for it. Any other shape is a single main block.

### Categories

cantrip.me has one custom category per deck card, so every line ends up with at most one, decided in this order:

1. an inline category on the line — Archidekt `[…]` or Moxfield `#…`; the first one when several are given (`[Ramp,Artifact]` → `Ramp`), Archidekt `{…}` flags stripped (`[Commander{top}]` → `Commander`);
2. otherwise the custom section header the line sits under;
3. otherwise none.

Some category names carry meaning rather than a category, matching what `ArchidektDeckMapper` already does for CSV:

| Category | Effect |
| --- | --- |
| `Commander` | command zone candidate (§4), no category |
| `Sideboard` | side zone, no category |
| `Maybeboard`, `Considering` | line dropped |
| the eight card types (`Creature`, `Instant`, `Land`, …, singular or plural) | no category |
| anything else | custom category, verbatim, cut to `DeckCategory::NAME_MAX` |

Lift `ArchidektDeckMapper::DEFAULT_TYPE_CATEGORIES` somewhere both importers read it rather than keeping a second copy.

Categories only apply to main and side lines; command zone and companion lines never carry one, as in the CSV import.

Output, one entry per non-noise line that is not dropped:

```php
array{
    line: int,               // 1-based, for the review UI
    raw: string,
    section: int,            // index of the block / header the line belongs to
    zone: 'main'|'side'|'companion'|'command',
    zone_guessed: bool,
    quantity: int,
    name: string|null,       // null when unparseable
    set: string|null,
    number: string|null,
    category: string|null,
}
```

plus a `dropped` count (maybeboard lines) for the review page.

### Fixtures

Before writing the parser, collect real exports — one file per site and export mode — under `tests/Fixtures/deck-lists/`:

- Moxfield: "Bulk edit" (no commander), "Export → Copy for MTGA", "Export → Copy for Moxfield"
- Archidekt: text export
- Draftsim: copy (type headlines, no set codes)
- MTGA export, MTGO `.txt`
- cantrip.me's own `/decks/{deck}/print` output — the round trip must be lossless

The formats in the tables above are from memory and drift without notice; the fixtures are the source of truth, and the tables get corrected against them.

## 2. Resolver

`App\Services\DeckList\DeckListResolver` — turns parsed lines into card references. Runs inside the parse endpoint, read-only.

Per line, first match wins:

1. **set + number** → that `default_cards` row (as the CSV import does today).
2. **set only** → the oracle card, printing chosen within that set (below).
3. **name only** → the oracle card, printing chosen across all printings (below).

Name matching is **exact on the normalized name**, not the LIKE search: `CardNameNormalizer::normalize($name)` compared with `=` against

- `oracle_cards.searchable_name` (full name, including `A // B`),
- front-face names (`oracle_card_faces.name`), so `Delver of Secrets` matches the transform card. That table has no `searchable_name` column: either compare in PHP after a coarse SQL prefilter on `name`, or add the column — which needs a new ALTER migration plus a backfill in `OracleCardsService`, since it has to reach prod.
- `oracle_card_translations` / `oracle_card_face_translations` (both carry `searchable_name`) for the same `OracleNameSearch::SEARCHABLE_LANGS` allowlist.

Batched: one query per source for all names in the paste, not one per line.

A name that matches no oracle card, or more than one, is **unresolved**. Unresolved lines get up to five suggestions from `OracleNameSearch` (the existing fuzzy LIKE search), which covers typos, renamed cards and Alchemy `A-` names.

### Printing choice — prefer the collection, like Quick Add

When the paste does not pin a printing, the resolver picks one the same way Quick Add does: the printing the user has the most **free** copies of in their collection, ties to the newest; the newest printing when nothing is free or the collection-integration master switch is off. Same rule, same code — `DeckCardSearchService::preferredPrintingId` over the free-copy map.

| Paste supplies | Printing |
| --- | --- |
| set + number | exactly that printing, owned or not — the user asked for it |
| set only | preference rule restricted to that set's printings; newest in the set if none is free |
| name only | preference rule over all printings |

"Free" means what it means everywhere else: not held by any deck, explicitly (`deck_card_card_stack`) or implicitly (in another deck's `container_id`). For a deck that does not exist yet that is simply *any* deck.

**Needs a user-level entry point.** `DeckCollectionStatusService::freeCopiesForOracles(Deck $deck, …)` cannot take an unsaved `Deck`: `partitionCopies` excludes "this deck" with `where('id', '!=', $deck->id)`, which with a null id is SQL `NULL` and drops every other deck from the held-elsewhere check — everything would read as free. Add `freeCopiesForUser(User $user, array $oracleIds)` — returning the free map *and* the per-oracle held counts the availability flag needs (below) — that runs the same partition with no deck excluded (parameterize `partitionCopies` on `userId` + `?excludeDeckId` and skip the `!=` clause when null). It honours the master switch like `freeCopiesForOracles`. The rule still lives once in PHP and once in SQL (`constrainToFreePrintings`); only the PHP side changes, and `FreePrintingsConstraintTest` must stay green.

The choice happens at **parse time**, so the review page shows the printing that will actually be imported, with the existing `CardFaceImage` ownership badge (`CardStackService::ownedAmountsFor`). One printing per line, as in Quick Add: `4 Lightning Bolt` with two free copies of one printing and one of another imports four of the first; splitting a line across printings is the deck page's "split printing" action.

The collection can change between parse and confirm; confirm takes the printing the review page showed and does not re-run the rule.

### Availability per line

Every resolved line also carries the planned-deck availability the deck page shows — the import creates a planned deck, so this is exactly what the user will see on the deck page a moment later:

| State | Icon | Meaning |
| --- | --- | --- |
| `available` | check / success | enough free copies of the chosen printing |
| `partial` | planned / warning | some copies free, but not enough of this printing — other printings, or too few |
| `unavailable` | money / error | nothing free: buy or trade |

The payload is `CollectionAvailability` (`state`, `needed`, `exact`, `other`, `blocked`), rendered by the existing `CollectionAvailabilityBadge` with its existing tooltip. No new component, no new strings.

Computed from the same partition as the printing choice, so the two never disagree, and with the same rule as `availabilityForDeck`: `exact` = free copies of the chosen printing, `other` = free copies of the oracle's other printings, `blocked` = copies other decks hold. To keep that rule in one place, lift the `match` in `availabilityForDeck` into a pure `availabilityFor(array $free, int $blocked, string $printingId, int $needed)` that both callers use, and have `freeCopiesForUser` return the held counts alongside the free map. Each line is judged on its own against all free copies, as on the deck page — two lines of the same card both see the same copies.

Switching a replacement card for an unresolved line recomputes its availability from the search response. Shown only while the collection-integration master switch is on; with it off, `availability` is null and the column is hidden, as on the deck page.

**Warnings, not errors** — computed per resolved line and shown on the review page, never blocking:

- not in the format's pool (`OracleCard::legalIn`)
- more copies than `FormatProfile::maxCopies()` allows
- outside the commander's colour identity — client-side, once a commander is picked, using the card's `color_identity` from the parse response

The deck page already flags illegal cards; the import does not refuse them.

## 3. Review page

What the user can change, and what stays on the deck page:

| Change | Here | Why |
| --- | --- | --- |
| Fix an unresolved line — pick a suggestion, search, or exclude | yes | nothing else can fix it |
| Exclude any line | yes | checkbox per line; cleans up junk without rebuilding the deck page's remove action |
| Command zone (commander + partner / background, or oathbreaker + signature spell) | yes, via "Change command zone"; required where the format needs it | see §4 |
| Zone of a section | yes, only for `zone_guessed` sections | one dropdown per block, not per card |
| Quantities, printings, adding cards, categories | no | the deck page does all of it |

Each line shows the chosen printing (with the ownership badge), its category if it has one, and its availability flag (§2, "Availability per line"), so the user sees before importing which cards they still have to buy or trade for. Skipped maybeboard lines are reported as a count, not listed.

Header: counts per zone against the format's `minDeckSize()` / `maxDeckSize()`. Confirm stays disabled until every line is resolved or excluded and the command zone is complete.

Search for a replacement card uses a deck-less endpoint. The deck-scoped `/api/decks/{deck}/card-search/*` routes need a deck; a small `GET /api/decks/import-list/search?format=…&q=…` over `OracleNameSearch` + `legalIn` is enough here.

## 4. Command zone

Only when `FormatProfile::requiresCommander()` is true (Commander, Duel, Brawl, Pauper Commander, PreDH, Standard Brawl, Oathbreaker).

The review page shows the command zone exactly as the create-deck page does: each selected card through `ShowCommanderOverview` (commander, partner / background or companion-type slot, signature spell), and a **"Change command zone"** button that opens the existing `CommanderCommandZonePickerModal` / `OathbreakerCommandZonePickerModal`. Both modals take `format` and search the deck-less `/api/commander` / `/api/oathbreaker` endpoints, so they work before the deck exists and need no changes.

That display + button block currently lives inline in `CreateEditDeckPage.vue` (twice — commander and oathbreaker variants). Extract it into one component, e.g. `Components/Deck/CommandZoneField.vue` (props: `format`, the selected cards; emits the picker's confirm payload), and use it on both pages, so the two cannot drift.

- **Pre-filled from the paste.** Cards from a `Commander` section or `[Commander]` category become the initial selection: first → commander, second → partner / background; for Oathbreaker the planeswalker → oathbreaker, the instant / sorcery → signature spell. The parse endpoint returns them in the picker's own result shape (`CommandZoneService::mapCommanderCard`), and only when they pass the same eligibility the picker's search applies for that format — an ineligible card stays a normal line with a warning, and the slot stays empty.
- **Nothing pre-filled** (Moxfield bulk edit, or an ineligible card): the block shows the empty state with the same button, labelled to choose rather than change. Confirm stays disabled until the format's required slots are filled.
- The server never trusts the pre-fill: confirm validates `commander_id` / `companion_id` / `signature_spell_id` with the create form's rules (§5).
- **Dedupe:** when the chosen commander / partner / signature spell is also in the pasted main list, one copy is removed from main and the review page says so. Otherwise a 100-card list imports as 101 with a singleton violation.

## 5. Confirm

`POST /decks/import-list` with a FormRequest (`authorize()` pattern, not `abort_unless`):

```
format, deck_name,
commander_id, companion_id, signature_spell_id,   // same fields as POST /decks/add
rows: [{ oracle_card_id, default_card_id, quantity, zone, category|null }]
```

Server side, in one transaction:

1. Validate exactly as `DecksController::store` does for the deck fields — same rules, factored out rather than copied — so a hand-crafted request cannot create a deck the create form would refuse. `rows.*` ids must exist, and `default_card_id` must be a printing of `oracle_card_id`.
2. `DeckService::createDeck(...)`.
3. Merge rows with the same `(oracle_card_id, default_card_id, zone, category)`.
4. Create the custom categories the rows name (`DeckCsvImportService::ensureCategories` already does this — one `deck_categories` row per distinct name, truncated to `NAME_MAX`), then write the rows with their `category_id`. `zone` is validated against main / side / companion only — `maybe` is not accepted, and `command` goes through the command-zone fields, not `rows`.

A replacement card picked on the review page (for an unresolved line) gets its printing from the same preference rule — the deck-less search endpoint returns it, as Quick Add's search does.

**Shared writer.** Step 4 is the same work `DeckCsvImportService::import` does inside its transaction, categories included. Extract that block into a writer both imports call, keyed by resolved ids; `DeckCsvImportTest` pins the CSV path's behaviour through the refactor.

Redirect to `/decks/{deck}` with the usual `decks.deck_created` flash.

## Limits

- Paste: 20 000 characters, 500 non-blank lines — validated on parse. A 250-card deck with headers fits comfortably.
- Parse is rate-limited like the other search endpoints.

## UI and i18n

- Entry point: a "Paste deck list" link next to "Import CSV" on `/decks`.
- Page: `resources/app/pages/Deck/Import/DeckListImportPage.vue`, beside `CsvImportPage.vue`.
- Strings under `pages.deck_list_import.*` in both lang files; `npm run i18n:check` covers them.

## Testing

| Layer | What | Where |
| --- | --- | --- |
| Parser | every fixture file → expected lines; header table; header-vs-card-line rule; blank-line guess; noise; maybeboard dropped and counted; categories — inline beats header, first of several, `{flags}` stripped, special names, type names | PHPUnit unit, pure |
| Resolver | set + number, set only, name, face name, translated name, ambiguous, unknown → suggestions | PHPUnit feature, `RefreshDatabase` (exact `=` matching runs on SQLite) |
| Printing choice | free copy beats newer printing; set-only stays inside the set; set + number wins even when unowned; stack held by a deck (pivot *and* deckbox) is not free; master switch off → newest; `freeCopiesForOracles` unchanged | PHPUnit feature; fixtures where the rules disagree |
| Availability | all three states; `blocked` counts pivot and deckbox holds; master switch off → null; `availabilityForDeck` output unchanged after the `availabilityFor` extraction | PHPUnit feature (`DeckShowAvailabilityTest` guards the deck page) |
| Confirm | validation parity with `store`; dedupe; merge; categories created once per name and assigned; `maybe` zone rejected; transaction rollback on failure; CSV import unchanged | PHPUnit feature |
| Review page | exclude, fix, zone dropdown, confirm gating, command zone pre-fill / empty state / change, availability badge per line and hidden when null | Vitest |
| `CommandZoneField` | create-deck page unchanged after the extraction | Vitest (existing create-page specs) |
| End to end | paste a Moxfield bulk-edit list → pick commander → deck page shows it | one Playwright spec; needs the cards in `E2ESeeder` |

## Delivery

Two PRs:

1. **Backend** — fixtures, parser, resolver, parse endpoint, writer extraction (CSV import unchanged), confirm endpoint. Fully tested without a UI.
2. **Frontend** — page, review step, `CommandZoneField` extraction + wiring, deck-less search endpoint, entry link, i18n, Vitest + Playwright.

## Decisions

- **New decks only**, first page asks for **name and format only** (2026-10-04).
- **Maybeboard is dropped**, not imported into the `maybe` zone; the review page reports how many lines were skipped.
- **Command zone reuses the existing pickers** behind a "Change command zone" button, shown like the create-deck page; pre-filled from the paste where it can be.
- **Custom categories are imported in v1** — inline `[…]` / `#…` and custom section headers.
- **Printings prefer the collection** (Quick Add's rule) when the paste does not pin one, and every line shows its availability.

## As built

Where the implementation differs from the plan above, and why.

- **Fixtures are reconstructions.** `tests/Fixtures/deck-lists/` was written by hand from each site's known export format, not captured from the sites (no access from the build environment). Its README says so. Replace each file with a real export when one is at hand; the parser test then shows what to fix.
- **The unsaved-`Deck` premise was wrong.** The query builder compiles `where('id', '!=', null)` to `IS NOT NULL`, so an unsaved `Deck` would *not* have made every copy read as free. `freeCopiesForUser(User, …)` was still added, because an explicit "no deck to except" is clearer than relying on that; its docblock gives the corrected reason.
- **Command-zone printing is the newest**, as on the create form — `DeckService::setCommandZone` picks it. A printing the paste named for its commander is not carried over; switching it is one click on the deck page.
- **Warnings that follow edits are client-side.** The parse response carries `is_legal`, `copy_limit` and `color_identity` per card; `resources/app/utils/deckListImport.ts` derives `not_legal`, `too_many_copies` and `color_identity` from the current exclusions, replacements and command zone. Notices the server alone knows (`printing_not_found`, `not_commander`) come with the line.
- **No `CardFaceImage` ownership badge on review lines.** The availability badge's tooltip already states the owned and free counts; a card image per line would have added a 404 per line on machines without the image cache and little else.
- **Deduped lines keep the availability computed for the pasted quantity.** It is one copy more conservative than the imported quantity; recomputing it client-side would have meant a second copy of the availability rule.
- **The type-name table was not shared with `ArchidektDeckMapper`.** The parser needs a superset (plurals, German labels from the print view, zone words), and changing the CSV mapper to it would change CSV behaviour. The two lists are documented side by side in `DeckListParser::HEADER_WORDS`.
- **Archidekt `{noDeck}` custom categories are dropped** like the maybeboard — they mark cards that are not in the deck (wishlists).
- **Extras:** `SB:` line prefixes (MTGO / deckstats) put a line in the sideboard; `About` and `Tokens` sections are skipped silently; a second companion line, or a companion in a format without the mechanic, goes to the sideboard.
- **Deck name**: optional on the paste form, editable on the review page, required to confirm; filled with the commander's name when empty, as the create form does.
- **`CHAR_LENGTH` under SQLite**: `DeckListImportTest` registers it as a PDO function so the suggestion and search paths — which share the MariaDB-only name ranking — run in the fast suite.
