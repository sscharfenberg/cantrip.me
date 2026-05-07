# Plan: Consolidate command zone + companion into `deck_cards`

> Status: scoped, ready to implement.
> Owner: Sven.
> Goal: collapse the `commanders` table and `decks.companion_*` columns into `deck_cards` rows tagged with a `role` column so that every card in a deck lives in one table and goes through one claim path.

## Decisions

1. **Data move strategy** — reseed staging. Edit the existing migrations to the target shape; no forward-only data migration needed.
2. **Companion zone** — `zone=companion` (separate from `side`).
3. **Hero image endpoints** — collapse the three (`setHeroImage`, `setCommanderHeroImage`, `setCompanionHeroImage`) into a single `setHeroImage(deck, deckCard)`.
4. **Single big-bang PR** — fine. Solo / alpha.
5. **Backgrounds** — fold under `role=partner`. The role represents "the second commander slot", not the MtG keyword. Same call applies to Friends Forever / Choose-a-Background / any future secondary-commander mechanic. Display labels ("Partner: X" / "Background: Y") are derived from the card's type line at render time, not stored on the deck_card.
6. **Add `proxy` to `card_stacks`** — riding along with this refactor since we're reseeding anyway. Storage-only in this PR; UI surfacing is deferred (see [Future work](#future-work)).

## Why

Today three card-shaped concepts live in three places:

| Concept       | Storage                                                       |
| ------------- | ------------------------------------------------------------- |
| Mainboard / sideboard | `deck_cards` (`zone = main | side`)                   |
| Command zone  | `commanders` pivot (PK `(deck_id, oracle_card_id)`, `is_partner`) |
| Companion     | `decks.companion_oracle_card_id` + `decks.companion_default_card_id` |

This forces every cross-cutting feature (collection-integration claims, hero image picker, finalize wizard, status badges, finalize service, bulk-add, validation, color-identity / singleton enforcement) to special-case three storage shapes. The next feature in the queue — claiming physical copies for commanders / companion — would either add a third claim mechanism (FK column on `commanders` + `decks`) or a parallel pivot table mirroring `deck_card_card_stack`. Both are duplications of work that already exists for `deck_cards`.

Consolidation pays back every future feature that touches "a card in this deck".

## Non-goals

- Changing MtG-rules-level semantics (color identity enforcement, singleton, format legality, banned-as-commander).
- Touching the `card_stacks` / containers side of the model.
- Changing the dual-ID pattern (`oracle_card_id` + `default_card_id` is preserved on `deck_cards`).

## Target schema

### `deck_cards` — new columns
- `role` — nullable enum. Values: `commander`, `partner`, `signature_spell`, `companion`. NULL for mainboard / sideboard rows. Stored as a string column cast via `App\Enums\DeckCardRole`.
- A unique index on `(deck_id, role)` enforces "at most one row per role per deck". MySQL/InnoDB UNIQUE allows multiple NULLs, so mainboard / sideboard rows are unaffected.

### `deck_cards.zone` — new enum cases
- Existing: `main`, `side`.
- Add: `command` (any command-zone slot — primary commander, partner, signature spell), `companion` (the companion card).
- Mapping table:

| Slot              | `zone`     | `role`             |
| ----------------- | ---------- | ------------------ |
| Mainboard card    | `main`     | `null`             |
| Sideboard card    | `side`     | `null`             |
| Primary commander | `command`  | `commander`        |
| Partner           | `command`  | `partner`          |
| Signature spell   | `command`  | `signature_spell`  |
| Companion         | `companion` | `companion`        |

### Tables / columns to drop
- `commanders` table — gone.
- `decks.companion_oracle_card_id`, `decks.companion_default_card_id` — gone.
- `App\Models\Commander` — gone.
- `decks.default_card_id` (hero image) **stays** — it's not commander-specific, it's the deck's banner art and may point at any printing the deck contains.

### `card_stacks` — new column (rides along)
- `proxy` — boolean, NOT NULL, default `false`. Edit the existing `2026_03_15_122628_create_card_stacks_table.php` migration. No UI changes in this PR — see [Future work](#future-work).

### Why a generic `role` and not `command_role`
The user pushed back on `command_role` to keep the column reusable for future role concepts (e.g. "lieutenant" / "background" if WotC ever ships another command-zone variant, or app-level roles like "primer feature card"). The four cases above are what the existing system distinguishes; the column is open for more.

## Data migration

This app is alpha and the staging server has real data the user doesn't want to reseed. The migration must preserve commanders and companions in flight.

### Path A — single-up migration (recommended)
One forward-only migration that:
1. `ALTER TABLE deck_cards ADD COLUMN role VARCHAR NULLABLE`.
2. Extend `deck_cards.zone` enum to include `command`, `companion`.
3. Backfill: for each row in `commanders`, insert a `deck_cards` row:
   - `deck_id`, `oracle_card_id`, `default_card_id` straight across.
   - `quantity = 1`.
   - `zone = command`.
   - `role = commander` if `is_partner = false`. If `is_partner = true`: `signature_spell` when the deck's format profile has `hasSignatureSpell()`, else `partner`. (Same disambiguation logic the controller's `edit()` uses today.)
   - `category_id = NULL`.
4. Backfill: for each `decks` row with `companion_oracle_card_id IS NOT NULL`, insert a `deck_cards` row with `zone=companion`, `role=companion`, quantity=1.
5. Add `UNIQUE(deck_id, role)`.
6. Drop FKs on `decks.companion_oracle_card_id` and `decks.companion_default_card_id`, then drop the columns.
7. Drop the `commanders` table.

Per the user's memory rule ("edit existing migrations, don't create new ones, for existing tables") — this is borderline. The schema *changes* belong in the original `2026_04_04_205106_create_deck_cards_table.php` and `2026_04_04_205056_create_decks_table.php` migrations, but the *data move* needs a new migration on staging because we can't `migrate:fresh` without losing user decks. Two options:
- Edit the original migrations to reflect the target shape (so a fresh deploy is clean), AND ship a one-off forward-only migration that does the data move on existing databases. The forward-only migration can be deleted once staging has run it.
- Or: if the user is willing to dump staging's decks/commanders/companions, edit the original migrations only and `migrate:fresh` + reseed.

Recommendation: edit-original + one-off data-move migration. Lower-risk and reversible by just re-importing from a backup.

### Path B — staged via adapter (rejected)
Keep both schemas live, route reads through a thin adapter, then cut over. Adds significant temporary code. Not worth it for an alpha-stage solo project.

## Code changes

### Backend

**Enums**
- `App\Enums\DeckZone` — add `Command`, `Companion` cases.
- `App\Enums\DeckCardRole` — new file. Cases `Commander`, `Partner`, `SignatureSpell`, `Companion`. Stored as string. Includes a small helper that maps a role to its i18n key.

**Models**
- `App\Models\DeckCard` — add `role` to `$fillable`, cast it to `DeckCardRole`. Add scopes: `commandZone()`, `companion()`, `mainboard()`, `sideboard()`.
- `App\Models\Deck` — drop `commanders()` BelongsToMany, `companion()`, `companionDefaultCard()`. Drop `companion_*` from `$fillable`. Add helper accessors that wrap deck_cards queries:
  - `commanders()` (returns Collection of DeckCard rows where zone=command, ordered: commander first, then partner/signature_spell)
  - `primaryCommander()` (single row where role=commander, or null)
  - `companion()` (single row where role=companion, or null)
- `App\Models\Commander` — delete. Anywhere it's imported needs replacing with `DeckCard`.

**Services**
- `App\Services\CommandZoneService` — keeps responsibility for command-zone search + validation (color identity, singleton, format legality). Internally points at `deck_cards` (`zone=command`) instead of the `commanders` table. `mapCommanderCard()` returns the same shape as before so callers don't churn.
- `App\Services\DeckBulkAddCollectionService` — drop the special-case loops added in the previous PR for commanders + companion. The deck_cards loop now covers them naturally. The `userOwnsPrinting` skip-guard goes away because we have a real claim mechanism via the existing pivot.
- `App\Services\DeckFinalizeService` — no logic change. The `cards[]` query used by the wizard already iterates `$deck->deckCards`, which now naturally includes command-zone + companion rows.
- `App\Services\DeckValidator` — replace `commanders` queries with `deck_cards` queries scoped by `zone=command` / `role=companion`. Same for color-identity and singleton checks.
- `App\Services\BracketSuggestionService` — if it touches commanders, repoint at deck_cards.
- `App\Services\DeckService::setCommandZone` (used by deck create / edit) — rewrite against deck_cards.

**Controllers**
- `App\Http\Controllers\Decks\DeckCommanderController` — rewrite or delete. Its create / swap / printing-update operations become deck-card writes with `role=commander`. Realistically keep the controller as the URL surface but have it write to `deck_cards`.
- `App\Http\Controllers\Decks\DeckCompanionController` — same.
- `App\Http\Controllers\Decks\DecksController`:
  - `show()`: `commanders` and `companion` props now come from filtering `deck_cards` by zone/role. Hero image lookup logic stays (operates on the `decks.default_card_id` column).
  - `finalize()`: cards query already iterates `deckCards` — automatically picks up commanders + companion now. The wizard's `cards[]` rendering loop just needs visual section headers for command zone / companion. Or keep one flat list and add a role badge per row.
  - `setHeroImage` / `setCommanderHeroImage` / `setCompanionHeroImage`: collapse into a single endpoint `setHeroImage(deck, deckCard)` since the source is now uniform. Frontend menus reroute through this.

**FormRequests**
- Anywhere a request validates a commander or companion (`SetDeckCommanderHeroImageRequest`, `RemoveDeckCompanionRequest`, `SetDeckCompanionRequest`, `SetDeckCompanionPrintingRequest`, `UpdateDeckCommanderPrintingRequest`, `ChangeDeckCommandZoneRequest`, `ShowDeckCommanderPrintingsRequest`, `ShowDeckCompanionPrintingsRequest`): rules now check ownership through `deck_cards` instead of the old structures. Mostly mechanical.

### Frontend

**Types**
- `resources/app/types/deckPage.ts`: `DeckCommander` and `DeckCompanion` types collapse into `DeckCardRow` with new optional fields `role: 'commander' | 'partner' | 'signature_spell' | 'companion' | null`. The legacy types stay around as type aliases for one PR if needed, then deleted.

**Pages**
- `pages/Deck/DeckPage.vue` — props drop the separate `commanders` / `companion` arrays; consumers filter `cards` by zone/role.
- `pages/Deck/DeckHeader.vue` — `hasCommanders` becomes `cards.some(c => c.zone === 'command')`.
- `pages/Deck/Cards/CardViewText.vue` + `CardViewImage.vue` — render the command zone + companion blocks via the same filter. The two views must stay in sync (per the project's existing rule).
- `pages/Deck/Finalize/DeckFinalizePage.vue` — the wizard's existing single list naturally includes commanders + companion. Add visual section headers ("Command zone" / "Companion") between role groups. Form payload shape stays flat (`assignments[deckCardId]` already covers all of them) — this is the consolidation's biggest single win.
- `pages/Deck/Modals/AddCompanionModal.vue` — rewrites to POST to a deck-card endpoint with role=companion (or its own thin wrapper that does the same).

**Per-card "Assign physical copy" picker**
Already implemented on deck_cards. Once command-zone / companion live in `deck_cards`, the picker just works on them too. The only remaining UX work is surfacing the picker in the command-zone block (it currently lives only in the mainboard / sideboard list).

### i18n
- Add role labels: `enums.deck_card_role.commander | partner | signature_spell | companion`. Reuse existing strings everywhere else.

### Tests
- Rewrite tests that touch the `commanders` table (`DeckValidatorCommanderBanTest`, finalize tests, command-zone service tests).
- Add tests that the new `(deck_id, role)` UNIQUE constraint catches duplicate commanders / partners / signature spells / companions.
- Add data-migration test (one row per shape) that asserts the backfill produces equivalent reads.

## Risks

1. **`decks.default_card_id` (hero image)** — currently can point at a commander or companion's printing. After the move, the hero printing might still resolve via `default_card_id` directly, but every flow that auto-suggests "use this commander's art" needs updating to look at the deck_cards row instead of the commanders pivot. Scope: small.
2. **Format profile rules** — `FormatProfile::hasSignatureSpell()`, `requiresCommander()`, `bannedAsCompanion()`, `enforcesColorIdentity()` are tested today against the old shape. Each needs a corresponding test against the new shape. Scope: moderate.
3. **Sideboard size enforcement** — companion currently lives outside `deck_cards`, so it doesn't count against the sideboard limit. After the move it lives at `zone=companion` (separate from `side`), so sideboard size check naturally excludes it. Verify the validator query.
4. **Hero image picker on edit page** — currently iterates commanders' printings + companion's printing + deck_cards' printings as three sources merged into one list. After the move it's one source: deck_cards. Simpler, but the `cardOptions` query in the edit method needs updating.
5. **Existing data migration** — the data-move migration is forward-only and not trivially reversible. Take a staging DB snapshot before running.
6. **Search / autocomplete endpoints** — `DeckCardSearchController::oracle` / `oracleCards` / `printings` filter the result set by what's already in the deck (to avoid duplicates). The "what's already in the deck" query needs to include commanders + companion now. Today it doesn't, because they're in different tables; after the move, naturally consistent.

## Phasing

Big-bang single PR is feasible because the project is alpha + solo, but a 3-PR split is safer:

**PR 1 — Schema + data migration + model layer (no UX change yet)**
- New `role` column, new `DeckCardRole` enum, new zone cases.
- One-off forward-only migration that backfills.
- Edit existing `create_deck_cards_table` and `create_decks_table` migrations to match target shape (for fresh deploys).
- Add scopes and accessors on `Deck` / `DeckCard` so the rest of the codebase has a clean read API to switch over.
- Old `commanders` table + `companion_*` columns still exist and are still being written.
- **Shippable: nothing visible, everything still works.**

**PR 2 — Read-side cutover**
- Switch every read in services / controllers / frontend from the old shape to the new one.
- `DecksController::show`, `finalize`, `edit`; `DeckValidator`; `CommandZoneService`; `BracketSuggestionService`; the wizard; the deck pages.
- **Shippable: identical UX, but reads now go through deck_cards.**

**PR 3 — Write-side cutover + cleanup**
- Switch every write (commander create / swap / printing change, companion add / remove / printing change, hero image picker) to deck_cards.
- Drop the `commanders` table, `decks.companion_*` columns, `App\Models\Commander`, the now-dead types.
- Surface the per-card "Assign physical copy" picker on the command-zone + companion blocks.
- Simplify `DeckBulkAddCollectionService` (drop the commander/companion special-case loops).
- **Shippable: net code reduction; the bulk-add and finalize wizard naturally claim commanders + companion as a side effect of being deck_cards.**

## Smoke tests (run at the end of the PR)

The unit + feature suite covers the invariants, but a refactor this wide deserves a manual walk-through of the user-visible flows. After all code is in and `composer test` is green, the implementer (Claude) writes up this checklist as the final step of the PR so the user can step through it on staging:

**Deck creation + command zone**
- [x] Create a Commander deck, pick a primary commander → renders in the command-zone block, has the right printing, hero image picks up the commander art.
- [x] Add a partner to the same deck → both render side-by-side; color identity panel reflects the union.
- [x] Swap the partner's printing → updates without losing the partner role.
- [x] Remove the partner → deck stays in mode "commander, no partner" cleanly.
- [x] Create an Oathbreaker deck → primary commander (planeswalker) and signature spell both render in the command zone, distinct labels.
- [x] Try to pick a banned-as-commander card *without* the rule-0 checkbox → submit fails with an inline `commander_id` error. The picker doesn't surface banned-as-commander cards in this state, so this is reachable only by hand-crafting a request.
- [x] Pick a banned-as-commander card *with* the rule-0 checkbox → deck saves; the LegalityPanel shows the `commander_banned` violation; the commander row renders the illegal-icon badge before the mana cost.
- [x] Add a card outside the commanders' color identity to the mainboard → save succeeds; the LegalityPanel shows the `color_identity` violation; the offending row renders `is_illegal=true`.
- [x] Add a duplicate copy of a non-basic card in a singleton format → save succeeds; the LegalityPanel shows the `copy_limit` violation; both rows render `is_illegal=true`.
- [x] Confirm the `(deck_id, role)` UNIQUE catches a manual attempt to insert a second `role=commander` (raw SQL or factory bypass — there's no UI path that should hit this).

**Companion**
- [x] Add a companion via the modal → renders in the companion panel.
- [x] Companion does NOT count against the sideboard size.
- [x] Swap companion printing → updates cleanly.
- [x] Remove companion → deck stays valid.
- [x] Hero image picker offers the companion's printing as an option.

**Hero image (collapsed endpoint)**
- [x] Set hero from a mainboard card → works.
- [x] Set hero from a commander → works via the same endpoint.
- [x] Set hero from a partner → works.
- [x] Set hero from a signature spell → works.
- [x] Set hero from a companion → works.
- [x] Hero image survives a deck save / page reload.

**Finalize wizard**
- [x] Open the wizard on a planned deck with commander + partner + companion + mainboard cards → all four sections appear, each row is pickable.
- [x] Pick a stack for the commander, hit submit → stack ends up pivot-attached to the commander deck_card; deck flips to Built; mode pins to C.
- [x] Re-open the deck show page → commander shows the "claimed_for_this_deck" badge.
- [x] Bought-new tick on the partner row creates a fresh stack of 1 → attached, deck mode stays C.
- [x] Skip path (no claims) → deck flips to Built without writing pivots, mode does NOT pin to C.

**Per-card "Assign physical copy" picker**
- [x] Open the picker on a commander row → lists owned stacks of that printing, pick one → claim writes through.
- [x] Open the picker on the companion → same.
- [x] Unclaim from a commander → stack goes back to "available" in the collection view.
- [x] Try to move a stack that's claimed for a commander → 422 with `cannot_move_claimed_stack`.

**Bulk-add ("Add all cards to collection")**
- [x] Run on a planned deck with commanders + companion + mainboard cards, no existing claims → fresh stack per row, including commanders + companion, all attached via the pivot, deck pins to mode C.
- [x] Re-run on the same deck → no new stacks created (every row already has a pivot).
- [x] Picked container value → all created stacks land in that container.
- [x] No container picked → all created stacks land unsorted.
- [x] "Set to finished" tick → state flips to Built.
- [x] Menu entry hidden on built decks (existing rule, verify still works).

**Mode A / B / C transitions**
- [x] User with no card_stacks → mode A on every deck, no per-card badges anywhere (commander/companion/mainboard).
- [x] User with stacks but no claims, deck has a deckbox → mode B, implicit "in this deckbox / elsewhere" badges show on commanders + companion + mainboard.
- [x] User with at least one claim on the deck → mode C, explicit badges show on every row including commanders + companion.
- [x] Clear all assignments → mode flips back to B (or A if no deckbox). In mode B with a deckbox set, the implicit count badges keep rendering — that's correct, the C-style explicit per-stack badges are what disappear.

**Color identity / singleton / format profile**
- [x] Color identity validator catches a mainboard card that's outside the (commander + partner) union.
- [x] Add a second non-basic copy in a singleton format → save succeeds; the LegalityPanel shows the `copy_limit` violation; both rows render `is_illegal=true`. (Adds are always permissive — the DeckValidator flags violations post-save, no 422 from the card-add endpoint.)
- [x] Format change is intentionally not exposed in the UI (the format select is rendered as disabled in edit mode), so this scenario is unreachable for users — no smoke test needed. Backend-side, `DeckService::setCommandZone` early-returns when `! requiresCommander() || $commanderOracleId === null`, so a manual format change via tinker would just leave the existing command-zone rows in place.

**Storage-only `card_stacks.proxy`**
- [x] Schema has the column with default `false`.
- [x] No existing UI touches the column (no toggle, no badge, no filter); existing flows all behave as before.
- [x] `php artisan tinker --execute "echo \Illuminate\Support\Facades\Schema::hasColumn('card_stacks', 'proxy') ? 'yes' : 'no';"` prints `yes`. (Verifies the column landed; the migration sets `default(false)` so any newly-inserted row picks up `false` automatically.)

**Regressions to watch**
- [x] CSV import still creates commander + companion correctly under the new shape.
- [x] CSV export still emits commander + companion with the right labels.
- [x] Public deck view (logged-out visitor) renders commanders + companion without mode-C status badges.
- [x] Deck-list page shows the right card count (commanders + companion now contribute via deck_cards naturally — verify the count matches what the user expects).

If any of the above fails, fix it before merging — these are the load-bearing flows.

## Future work

Items intentionally deferred from this PR:

- **Surface proxies in the UI.** The `card_stacks.proxy` column ships in this refactor (storage-only). Follow-up PR(s) need to:
  - Add a "this is a proxy" toggle in the card-stack create / edit forms.
  - Show a proxy badge on stacks in the collection view.
  - Decide whether proxies count as "owned" for the deck-card status badges in modes B / C (probably yes, but may want a separate `proxy_for_this_deck` status).
  - Decide whether the card_stack uniqueness key (`user_id + default_card_id + language + condition + finish + container_id`) should also include `proxy` so foil + proxy + nonfoil don't merge.
  - Decide whether the deck-card price aggregation should exclude proxies.
