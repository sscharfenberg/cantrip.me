# Foreign-language card name search

## Context

Today `default_cards` stores one printing per oracle in English (or printed language if the card was never printed in English). Foreign-language printings exist in Scryfall's `all_cards` bulk JSON (~2.5 GB) but are not currently ingested. A German user searching "Blitzschlag" cannot find Lightning Bolt — they have to know the English name. This document covers the plan to add an ingestion step for `all_cards`, store foreign-language card names in two new translation tables, and extend the search services that today match against `oracle_cards.searchable_name` to also match against translations.

Goals:
- Find an oracle card by any printed-language name (e.g. "Blitzschlag", "Foudre", "稲妻") → returns the English `OracleCard`.
- Zero impact to read performance on the English search path (the only addition is a single composite-indexed `WHERE EXISTS` subquery).
- Fit cleanly into the shadow-table architecture so a partial import never breaks live.

Decisions taken during design:
- **One row per `(oracle_card_id, lang)`** for oracle-level translations; **`(oracle_card_id, face_index, lang)`** for face-level. Matches Scryfall's natural grain; dedupe happens in memory during the streaming parse.
- **Name only** for v1 — no `printed_text`, no `printed_type_line`. `printed_type_line` is too sparse on Scryfall to be useful; `printed_text` has no concrete UI surface yet and would quadruple disk + RAM cost. Both can be added by later migrations.
- 17 non-English `ScryfallLang` cases are all imported.

## Schema

Two new MariaDB tables. Both `utf8mb4_unicode_ci`, InnoDB.

```
oracle_card_translations
  oracle_card_id   uuid  FK → oracle_cards.id  ON DELETE CASCADE
  lang             string(8)
  printed_name     string(255)
  searchable_name  string(255)            // CardNameNormalizer::normalize(printed_name)
  PRIMARY KEY (oracle_card_id, lang)
  INDEX (lang, searchable_name)           // search hot path

oracle_card_face_translations
  oracle_card_id   uuid  FK → oracle_cards.id  ON DELETE CASCADE
  face_index       tinyint unsigned
  lang             string(8)
  printed_name     string(255)
  searchable_name  string(255)
  PRIMARY KEY (oracle_card_id, face_index, lang)
  INDEX (lang, searchable_name)
```

Face translations FK cascades through `oracle_card_id` only — when an oracle is deleted, both its `oracle_card_faces` rows and its face translations cascade. No need for a composite FK to `(oracle_card_id, face_index)` since the data only ever turns over via full truncate-rebuild.

Index rationale: the hot query is `WHERE lang = ? AND searchable_name LIKE '%term%'`. `(lang, searchable_name)` is optimal — equality on lang slices to ~30k rows, the unanchored `LIKE` then filtered-scans within the slice. This mirrors how the existing `oracle_cards.searchable_name` searches behave. No FULLTEXT (would diverge from the project's `LIKE '%…%'` pattern and break the SQLite test path).

## Reuse — existing helpers / building blocks

- `App\Enums\Scryfall\ScryfallLang` (`app/Enums/Scryfall/ScryfallLang.php`) — all 18 cases already defined. Skip `En` at import; store the other 17.
- `App\Services\CardNameNormalizer::normalize()` (`app/Services/CardNameNormalizer.php:33`) — exact pipeline used by `oracle_cards.searchable_name` and `CardSearchParser`. Reuse verbatim so foreign + English search behave identically.
- `App\Services\Scryfall\BulkdataService::getBulkMetadata()` (`app/Services/Scryfall/BulkdataService.php:163`) — already records the `all_cards` row from Scryfall's catalog. No change to `scryfall:bulk` needed. `TranslationsService` reads the `download_uri` straight off the `bulk_data(__shadow)` row.
- **Not reused: `BulkdataService::prepareJson()` / `postRunCleanup()`.** The `all_cards` bulk is ~2.5 GB and `prepareJson` materializes the entire HTTP response in PHP memory via `$response->body()` before writing to disk — fine for `rulings`/`default_cards`, but it OOMs for `all_cards`. `TranslationsService` streams the JSON directly from Scryfall via `JsonParser`'s `Endpoint` source (see "Download / parse strategy" below). No on-disk file, no prepare/cleanup step.
- `App\Services\Scryfall\Shadow\ShadowTableRegistry` (`app/Services/Scryfall/Shadow/ShadowTableRegistry.php`) — add two entries to `TABLES` and two entries to `FK_CHECKS`. Everything else (orphan validation, capture/drop/restore of FKs, atomic swap, end-of-run row counts) is registry-driven and picks up the new tables automatically.

## Files to create

| File | Purpose |
|------|---------|
| `database/migrations/<ts>_create_oracle_card_translations_table.php` | Oracle-level table |
| `database/migrations/<ts+1>_create_oracle_card_face_translations_table.php` | Face-level table |
| `app/Models/OracleCardTranslation.php` | Model; composite PK, `lang` cast to `ScryfallLang`; `belongsTo(OracleCard)` |
| `app/Models/OracleCardFaceTranslation.php` | Same pattern |
| `app/Services/Scryfall/TranslationsService.php` | Streaming parser; mirrors `RulingsService` structure |
| `app/Console/Commands/Scryfall/UpdateTranslations.php` | `scryfall:translations {--target=live\|shadow}` |
| `tests/Unit/Services/Scryfall/TranslationsServiceTest.php` | Parse-side unit tests (SQLite, `RefreshDatabase`) |
| `tests/Feature/Services/CommandZoneServiceTest.php` (new file) | Foreign-language commander/oathbreaker search regression tests (mysql suite) |

## Files to modify

| File | Change |
|------|--------|
| `app/Services/Scryfall/Shadow/ShadowTableRegistry.php:27` | Append `oracle_card_translations` + `oracle_card_face_translations` to `TABLES` |
| `app/Services/Scryfall/Shadow/ShadowTableRegistry.php:54` | Append two `(translation_table, oracle_card_id, oracle_cards, 'shadow')` entries to `FK_CHECKS` |
| `app/Models/OracleCard.php` | Add `translations(): HasMany` relation |
| `app/Console/Commands/Scryfall/UpdateEverything.php` | Insert `scryfall:translations --target=shadow` step **after** `scryfall:oracle-tags`, **before** `scryfall:default_cards`. Update the orchestration docblock. |
| `app/Services/DeckCardSearchService.php` | Replace `applyNameSegments` calls at lines ~101, ~208, ~348 with a helper that ORs against `oracle_card_translations` + `oracle_card_face_translations` via `whereExists` subqueries keyed by `lang + searchable_name`. Ranking (`applyNameRanking`) stays as-is — foreign matches fall to the alphabetical tiebreaker; acceptable tradeoff. |
| `app/Services/CommandZoneService.php:60`, `:240` | Same `whereExists`-OR pattern for commander / oathbreaker search |
| `app/Services/DefaultCardSearchService.php:72` | Same — extend the oracle prefilter |
| `tests/Unit/Services/Scryfall/Shadow/ShadowTableRegistryTest.php` | Extend the expected-tables assertion to cover the new entries |
| `tests/Feature/Services/DeckCardSearchServiceTest.php` | Add German/French/Japanese name-match cases for deck card-add search |
| `tests/Feature/Services/DefaultCardSearchServiceTest.php` | Add German/French/Japanese name-match cases for the "Add card to collection" + container hero-image picker flows |
| `docs/artisan-commands.md` | Add the new `scryfall:translations` command entry alongside `scryfall:oracle` / `scryfall:rulings`; add the new step to the `scryfall:update` execution-order block at the top of the file |

**Not changed (intentional):** `CollectionController` + `ContainerController` DataTable search query `default_cards.name` directly. A user who owns a German Lightning Bolt has `default_cards.name = "Blitzschlag"` stored on that printing already, so existing search already works within the user's owned cards. Translations exist to find cards the user *doesn't* yet own, which is the deck-builder / collection-add flow, not the collection browser.

## Download / parse strategy

`TranslationsService` parses the bulk via `JsonParser::parse($bulkData->download_uri)` — `cerbero/json-parser` exposes `Endpoint` and `Filename` source types under `vendor/cerbero/json-parser/src/Sources/`, and `Endpoint` streams the HTTP response chunk-by-chunk through the parser without ever materializing the full file. No file is written to disk; no `prepareJson` / `postRunCleanup` calls.

**Why this differs from `RulingsService` / `DefaultCardsService` / `OracleCardsService`:** those services call `JsonParser::parse(Storage::disk('scryfall-bulk')->get($fileName))` — `Storage::disk()->get()` returns the file as a string, which materializes the whole file in PHP memory before the parser sees it. Works for `rulings` (25 MB) and `default_cards` (537 MB) given an elevated `memory_limit`, but breaks for `all_cards` (~2.5 GB). When copying from those services as a template, **do not** copy the `Storage::disk(...)->get(...)` call — copy the surrounding flush/dedupe/buffer shape only.

Tradeoff accepted: a parse-time abort means a full re-download next run. The bulk is large, but `scryfall:update` is a nightly cron, so a re-download window is acceptable. If this becomes painful, the fix is to swap to `Filename` source + a streaming Guzzle `sink` download — separate refactor, not in scope for v1.

## Parser algorithm (TranslationsService)

Single streaming walk via `JsonParser::parse($bulkData->download_uri)`. For each printing:

1. Skip if `lang === 'en'` (English oracle is already in `oracle_cards`).
2. Skip if `printing.oracle_id` is not in the pre-loaded `oracle_cards(__shadow)` id set (defensive — token alts etc.).
3. Resolve oracle-level `printed_name`: top-level `printed_name` if present; else `card_faces[0].printed_name`. If still null, skip oracle-level.
4. Dedupe oracle-level into `oracleBuffer[oracle_id|lang]` (first one wins — same translation across reprints).
5. For each `card_faces[$i]` with `printed_name`:
   - `faceOracleId = face.oracle_id ?? printing.oracle_id` (reversible cards have per-face oracle_ids).
   - Skip if `(faceOracleId, $i)` not in the pre-loaded `oracle_card_faces(__shadow)` set.
   - Dedupe into `faceBuffer[faceOracleId|i|lang]`.

After walk: flush both buffers via `DB::table($shadowTable)->insert($chunk)` in 1000-row chunks. Eloquent is unsuitable here for the same shadow-mode reason documented in CLAUDE.md (hardcoded `$table`).

`searchable_name` columns are populated via `CardNameNormalizer::normalize($printedName)` in PHP during the buffer-write step, mirroring how `OracleCardsService` writes its own searchable column. No DB-side normalization.

Peak RAM estimate: ~65 MB. ~300k oracle entries + ~150k face entries × ~140 B/entry. Fits comfortably.

## Shadow-flow placement

Order in `UpdateEverything`:

```
… → oracle → oracle-tags → translations → default_cards → rulings → images → …
```

Translations depend on `oracle_cards__shadow` AND `oracle_card_faces__shadow` (both populated by `scryfall:oracle`). They are independent of `default_cards`, `rulings`, and images.

Standalone invocation (`php artisan scryfall:translations`) defaults to `--target=live`: truncates both translation tables, runs the same parse against live `oracle_cards` / `oracle_card_faces`. Useful for a forced one-off refresh between full updates.

## Search integration shape

New helper on `DeckCardSearchService` (re-applied by `CommandZoneService` and `DefaultCardSearchService` — extract to a trait or static helper if duplication gets ugly):

```php
$query->where(function (Builder $q) use ($segment): void {
    $q->where('oracle_cards.searchable_name', 'like', "%{$segment}%")
      ->orWhereExists(function ($sub) use ($segment): void {
          $sub->select(DB::raw(1))
              ->from('oracle_card_translations as oct')
              ->whereColumn('oct.oracle_card_id', 'oracle_cards.id')
              ->where('oct.searchable_name', 'like', "%{$segment}%");
      })
      ->orWhereExists(function ($sub) use ($segment): void {
          $sub->select(DB::raw(1))
              ->from('oracle_card_face_translations as ofct')
              ->whereColumn('ofct.oracle_card_id', 'oracle_cards.id')
              ->where('ofct.searchable_name', 'like', "%{$segment}%");
      });
});
```

The query plan uses the `(lang, searchable_name)` index on the translation tables; absent a `lang` filter (we don't have one — user language preference isn't part of search criteria) the optimizer falls back to using `oracle_card_id` for the join and filtering on `searchable_name`. If `EXPLAIN` shows a full scan after deploy, we can add a `lang` filter from the user's `users.locale` to slice.

## Tests

- **Unit**: parse correctness against a small JSON fixture — dedupe across reprints, face extraction for transform layouts, skip behavior for unknown oracles + English + missing `printed_name`. Asserts `searchable_name` matches `CardNameNormalizer::normalize($printed_name)`.
- **Unit**: `ShadowTableRegistryTest` — registry includes the two new tables.
- **Feature (mysql suite)** — three integration sites, each gets its own coverage:
  - `tests/Feature/Services/DeckCardSearchServiceTest.php` (existing, add cases) — deck "Add card" search via German/French/Japanese names returns the English oracle; English search unchanged (regression).
  - `tests/Feature/Services/DefaultCardSearchServiceTest.php` (existing, add cases) — "Add card to collection" + container hero-image picker via foreign names; English regression.
  - `tests/Feature/Services/CommandZoneServiceTest.php` (new file) — commander + oathbreaker pickers via foreign names; English regression.
- The existing `tests/Feature/Services/Shadow/*` tests are registry-driven and require no edits — the new tables get exercised by `ShadowTableServiceTest::it_creates_like_for_every_registered_table` and friends automatically.

## PR split

**PR 1 — data layer + import**
- Migrations, models, `TranslationsService`, `UpdateTranslations` command, `OracleCard::translations` relation
- `ShadowTableRegistry` additions
- `UpdateEverything` invocation
- Unit tests + `ShadowTableRegistryTest` update
- `docs/artisan-commands.md` update (new command entry + orchestration order)
- Manual: run `scryfall:bulk && scryfall:translations` on staging to populate live before PR 2 lands

**PR 2 — search integration**
- `DeckCardSearchService`, `CommandZoneService`, `DefaultCardSearchService` extensions
- Feature tests for foreign-language search across all three integration points (deck card-add, "Add card to collection" + container hero-image picker, commander/oathbreaker pickers)
- This document gets a search-integration appendix once landed
- Manual: hit deck card-add, the "Add card to collection" page, and the commander picker in staging — query "blitzschlag" / "foudre" / "稲妻" on each

PR 1 ships data with no behavior change (translations exist but nothing reads them). PR 2 is small and focused — easy to review for behavior.

## Verification

After PR 1 lands:
- `php artisan scryfall:translations` runs to completion on staging (~5–8 min for the combined stream + parse — the JSON is fetched from Scryfall and parsed in one pass, no on-disk cache).
- `SELECT COUNT(*) FROM oracle_card_translations` ≈ 300k; `SELECT COUNT(*) FROM oracle_card_face_translations` ≈ 150k.
- `SELECT * FROM oracle_card_translations WHERE searchable_name = 'blitzschlag' AND lang = 'de'` returns the Lightning Bolt oracle.
- `EXPLAIN SELECT … WHERE lang = 'de' AND searchable_name LIKE 'blitz%'` shows `range` or `ref` on the `(lang, searchable_name)` index.
- Run a full `scryfall:update` and confirm the orchestrator end-of-run row-count summary includes the two new tables and the swap completes atomically.

After PR 2 lands:
- Deck "Add card" search → type "Blitzschlag" → Lightning Bolt appears in results.
- Deck "Add card" search → type "Lightning Bolt" → still works exactly as before (regression check).
- Commander picker → type "Atraxa" (English) and alt-language printings → both resolve.
- `composer test:mysql -- --filter=DeckCardSearchServiceTest` passes.
- `composer test` passes (English path regression).

## Critical files (for the implementer)

- `app/Services/Scryfall/Shadow/ShadowTableRegistry.php` — registry; two-line additions.
- `app/Services/Scryfall/RulingsService.php` — closest template for `TranslationsService` shape (single-pass parse, shadow-aware insert).
- `app/Services/Scryfall/OracleCardsService.php` — reference for how oracle + faces get populated in one pass (so we know exactly what's available in `oracle_cards__shadow` and `oracle_card_faces__shadow` by the time translations runs).
- `app/Services/CardNameNormalizer.php` — read-only reuse for `searchable_name` generation.
- `app/Console/Commands/Scryfall/UpdateRulings.php` — template for the new command's shape.
- `app/Console/Commands/Scryfall/UpdateEverything.php` — orchestrator; one new `runStep` call + docblock update.
- `app/Services/DeckCardSearchService.php` — primary search integration site.
- `app/Services/CommandZoneService.php` and `app/Services/DefaultCardSearchService.php` — secondary search integration sites.