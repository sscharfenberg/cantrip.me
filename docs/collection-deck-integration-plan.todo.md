# Collection ↔ Deck Integration — implementation plan

Companion to `collection-deck-integration.todo.md` (the design doc). The design doc captures *what and why*; this plan captures *how and in what order*. Two phases — phase 1 is the headline MVP, phase 2 fills in the corners.

Per-step staging smoke tests live in `collection-deck-integration.smoke-tests.md`. Each shipped step appends its own section there — keep new steps in sync.

## Status (2026-05-01)

**Phase 1 is shipped and smoke-tested on staging.** All five sub-steps (1.1 schema/lifecycle, 1.2 mode detection, 1.3 status badges, 1.4 finalize wizard, 1.5 settings toggle) are merged. The full nine-section smoke-test plan against real Scryfall data passed end-to-end.

Two design refinements emerged during phase-1 smoke-testing and are baked in:

- **Sticky mode C.** Originally mode was inferred purely from current pivot-row count. Deleting the last claimed stack from the collection cascade-removed its pivot row and silently dropped the deck back to mode B, hiding all status badges. Per the design doc, C→B is rare and explicit ("clear all collection assignments"), never implicit. Fix: `decks.collection_mode` (nullable string, length 1) gets pinned to `'C'` the first time the wizard claims a stack and stays put until a future explicit C→B action clears it. `effectiveMode` checks the pin before falling back to the pivot count.
- **Mode-B decks stay silent (until 2.2).** A first-pass overshoot let mode-B decks render badges so a sibling deck's claim could surface as `claimed_by_other_deck`. That was design-non-faithful — mode B was always meant to be quiet until Phase 2.2's count-based display ships. Reverted; cross-deck claim visibility now lives in 2.2.

**Phase 2 is mostly shipped.** 2.1 (per-card assign picker), 2.2 (mode-B implicit display), 2.3 (mode-aware gating audit + deck-edit container picker), and 2.6 (collection-mode badge + modal in the deck header) are done and smoke-tested on staging. Two steps left — 2.4 "bought new" wizard checkbox, 2.5 collection-side claim badges.

Two refinements from phase-2 smoke-testing are baked in:

- **Mode/badge separation.** `effectiveMode` returns mode B regardless of `decks.container_id` (so the planned→built wizard fires correctly — the wizard is where the user picks a container). Per-row implicit badges and the header `collectionBadgeMode` independently demote to A when mode B has no anchor, so the UI never advertises "Implicit tracking" while no per-row badges actually render. The modal heading mirrors the badge presentation; the modal's why-recap and actions still use the real mode so the no-container case gets the "Edit deck" + promote action set.
- **Boost off in staging.** Laravel Boost's browser-logs proxy was active on staging and POSTed Vue console warnings (containing `onClose=fn` and similar) to a public route. ModSecurity flagged them as XSS, repeated 403s tripped fail2ban. Set `BOOST_ENABLED=false` in staging `.env` to disable the proxy; Boost remains usable locally for development.

## Phase 1 — MVP (shipped)

The minimum that ships the headline value: a deck owner can claim physical copies from their collection at the planned→finished transition, and the deck view surfaces ownership status per card.

### Step 1.1 — Schema + lifecycle ✅ shipped

**Migrations** (project convention: edit existing migrations for existing tables; only create new files for genuinely new tables):
- **New** `create_deck_card_card_stack_table.php` — pivot table for the new many-to-many.
  - Columns: `deck_card_id` (uuid FK → `deck_cards.id`, cascade on delete), `card_stack_id` (uuid FK → `card_stacks.id`, cascade on delete), `created_at`.
  - Composite primary key on `(deck_card_id, card_stack_id)`.
- **Edit** `2026_04_04_205106_create_deck_cards_table.php` — remove the dormant `card_stack_id` column + its index from the original migration.
- **Edit** `0001_01_01_000000_create_users_table.php` — add `collection_integration_enabled` (`boolean`, `default true`, `not null`). User-level opt-out toggle for the entire collection-integration feature; default `true` for everyone (the inferred mode keeps users without stacks silent until data exists).
- **Edit** `2026_04_04_205056_create_decks_table.php` — add `collection_mode` (nullable `string`, length 1). Sticky mode-C marker; null = mode is inferred (A or B based on user state), `'C'` = the deck has been pinned by the wizard or per-card assign. Stays put even if every pivot row is later cascade-deleted via stack removal. Resolves the otherwise-implicit C→B regression that hides badges when users delete claimed stacks. *(Added during smoke-testing — see "Status" at the top of this doc.)*

**Models:**
- `app/Models/DeckCard.php`
  - Remove `card_stack_id` from `$fillable`.
  - Add `cardStacks(): BelongsToMany` relation.
- `app/Models/CardStack.php`
  - Add `deckCards(): BelongsToMany` relation.
  - Add `isClaimed(): bool` accessor (`return $this->deckCards()->exists()`).
- Show payload in `DecksController::show()` — drop the `'card_stack_id' => $dc->card_stack_id` line at line 257.

**Service additions:**
- `app/Services/CardStackService.php`
  - New method: `splitStack(CardStack $stack, int $amountToSplit): CardStack`. Mirrors `DeckCardController::split`'s pattern. Decrements the source stack's `amount`, creates a new stack with the split amount, returns the new stack. Used by the wizard when a stack's `amount` exceeds the deck card's `quantity`.

**Lifecycle guards:**
- `UpdateCardStackRequest` (existing) — extend with a validation rule blocking `container_id` changes when the stack has any pivot rows. Translate via a new lang key `collection.errors.cannot_move_claimed_stack`.
- `MoveSelectedCardStacksRequest` (existing) — same rule, applied across all selected IDs.
- Cascade behaviours fall out of the migration FKs (deck deleted → deck_cards cascade → pivot cascade; stack deleted → pivot cascade).

**Tests** (`tests/Feature/Services/`):
- Pivot relations work both directions.
- Cascade delete: deck → deck_cards → pivot rows; stack → pivot rows.
- Container move blocked when stack is claimed; allowed once unclaimed.
- `splitStack` correctness on `amount` math.

**No frontend changes in this step.** Schema-only migration, dormant column removal.

---

### Step 1.2 — Mode detection + status query ✅ shipped

**New service:** `app/Services/DeckCollectionStatusService.php`
- `effectiveMode(User $user, Deck $deck): string` — returns `'A' | 'B' | 'C'`.
  - **Early-return:** `if (! $user->collection_integration_enabled) return 'A'`. The user-level opt-out forces mode A regardless of stacks/pivot state.
  - Counts user's `card_stacks` (zero → A regardless of deck state).
  - **Sticky-pin check:** `$deck->collection_mode === 'C'` short-circuits to C before counting pivots, so the deck stays in C even if every claimed stack was later deleted from the collection.
  - Counts pivot rows for this deck.
  - A: zero stacks; B: stacks but neither sticky-pinned nor with pivot rows; C: sticky-pinned OR pivot rows exist.

**Share the toggle to the frontend:** `app/Http/Middleware/HandleInertiaRequests.php` — extend the `auth.user` block with `'collection_integration_enabled' => $request->user()->collection_integration_enabled` (mirrors the existing `deck_view_default` / `deck_sort_default` pattern at lines 51–52).
- `statusForDeck(Deck $deck): array<string, string>` — keyed by `deck_card_id`, value is one of `claimed_for_this_deck | available | claimed_by_other_deck | wrong_printing | not_owned`. Single batched query joining `deck_cards`, `card_stacks`, pivot, and `oracle_cards.id` for the wrong-printing fallback.

**Performance shape:**
- One query per deck regardless of card count.
- Fetches: stacks owned by user matching any of the deck's `default_card_id`s OR `oracle_card_id`s, plus their pivot membership across decks.
- Resolution in PHP: per deck_card row, walk owned stacks and pick the highest-priority state (claimed_for_this_deck > available > claimed_by_other_deck > wrong_printing > not_owned).

**Tests** (`tests/Feature/Services/`, MariaDB-only — joins legacy data):
- `effectiveMode` returns each value correctly given seed data.
- `statusForDeck` returns each of the 5 states for a stub deck with crafted stacks.
- Performance smoke: single query (assert via `DB::listen` query log).

**No UI yet.** Verifiable via `php artisan tinker` and tests.

---

### Step 1.3 — Per-row status badges (mode C) ✅ shipped

**New Vue component:** `resources/app/components/Deck/CollectionStatusBadge.vue`
- Props: `status: 'claimed_for_this_deck' | 'available' | 'claimed_by_other_deck' | 'wrong_printing' | 'not_owned'`.
- Renders icon + colour + tooltip following the existing legality / game-changer badge pattern.
- Icon/colour map per the design doc's status taxonomy table.

**Type extension:** `resources/app/types/deckPage.ts` — add `collection_status?: string` to `DeckCardRow`.

**Controller wiring:** `DecksController::show()`
- Resolve effective mode via `DeckCollectionStatusService`.
- When mode === `'C'`, call `statusForDeck` and inject the result into the per-deck-card payload (alongside existing fields like `is_illegal`).
- Add `collection_mode: 'A' | 'B' | 'C'` to the top-level Inertia props.

**Page wiring:**
- `DeckPage.vue` — accept `collection_mode` prop, drill into `CardViewText` and `CardViewImage`.
- `CardViewText.vue` and `CardViewImage.vue` — render `<collection-status-badge>` next to existing flags when `collection_mode === 'C'` and `card.collection_status` is set.

**i18n:** `resources/app/lang/{de,en}.json` — tooltip strings for each of the 5 status states. Probably under `pages.deck.collection_status.{state}`.

**Tests:** type-check, lint. UI smoke on staging — verify badges appear for a deck with assignments and don't appear for a deck without any.

---

### Step 1.4 — Wizard at planned→finished (new page) ✅ shipped

**Routing:**
- New auth-gated route: `GET /decks/{deck}/finalize` → `DecksController::finalize`, name `decks.finalize`.
- New auth-gated route: `POST /decks/{deck}/finalize` → `DecksController::storeFinalize`, name `decks.finalize.store`.

**FormRequests:**
- `FinalizeDeckRequest extends FormRequest` — owner-only authorize, validates the assignment payload structure.
- Both routes use it (page render + submit).

**Controller methods (`DecksController`):**
- `finalize(FinalizeDeckRequest $request, Deck $deck): Response`
  - Loads deck cards, user's matching stacks (grouped by `default_card_id`).
  - Renders `Deck/Finalize/DeckFinalizePage`.
- `storeFinalize(FinalizeDeckRequest $request, Deck $deck): RedirectResponse`
  - On submit: persists pivot rows, auto-splits where needed, sets `decks.container_id` if provided, transitions `deck.state` to `Built` (or whatever the "finished" enum case is).
  - On skip: just transitions state, no pivot writes.
  - Redirects to `decks.show` with flash.

**Auto-split logic in `storeFinalize`:**
- For each deck_card requested with N assignments where the deck_card's `quantity > N`: split the deck_card into one row of `quantity = N` (with pivot rows) and one row of `quantity = remainder` (no pivot).
- For each card_stack used where the stack's `amount > 1` and the deck_card asks for fewer than `amount`: call `CardStackService::splitStack` first, then assign the split-off stack.

**State-transition entry point:**
- New menu item in `DeckActionsMenu.vue`: "Set to finished" (or matching the existing copy under `pages.decks.actions.set_built` if it already exists).
- Click handler:
  - Mode A → directly PATCH the deck state to `Built` and redirect with flash. No wizard.
  - Modes B and C → `router.visit('/decks/{deck}/finalize')`. The wizard page handles the actual state transition on submit/skip.
- Owner-gated like the other entries (the menu is already inside `v-if="isOwner"`).

**Frontend page:** `resources/app/pages/Deck/Finalize/DeckFinalizePage.vue`
- Two-column layout: needs on the left, dropdown per row on the right.
- Per row: list owned stacks for that `default_card_id`, formatted `[ContainerType]: [ContainerName] (×amount)`, with "not available" when none match.
- Below list: optional deckbox dropdown. Lists **all** of the user's containers — but Deckbox-typed ones sorted to the top, with an inline hint that "Deckbox is the recommended container type for this." Preselects `decks.container_id` if already set.
- Bottom bar: Submit / Skip / back-to-deck.
- Partial coverage: when wizard offers fewer than the deck card's `quantity`, show a small inline note ("3 of 4 will be claimed; 1 will remain unassigned").

**i18n:** wizard-specific strings — header, table column labels, button labels, partial-coverage hint, flash messages. Under `pages.deck.finalize.*`.

**Tests:**
- Feature test: finalize endpoint creates pivot rows correctly.
- Feature test: auto-split deck_card on partial coverage.
- Feature test: auto-split card_stack when stack `amount` exceeds requested quantity.
- Feature test: skip just transitions state, no pivot writes.
- Type-check, lint.

---

### Step 1.5 — Settings UI for the collection-integration toggle ✅ shipped

Surfaces the user-level opt-out flag added in step 1.1. Mirrors the existing `deck_view_default` / `deck_sort_default` pattern.

**Backend:**
- New controller: `app/Http/Controllers/User/CollectionIntegrationController.php`. Single `update(Request $request)` method validating `'collection_integration_enabled' => ['required', 'boolean']`, persisting on the user, redirecting back with a flash.
- New route inside the auth middleware group: `POST /collection-integration` named `collection_integration.update`.
- (`HandleInertiaRequests.php` already shares the flag — done in step 1.2.)

**Frontend:**
- Extend the `auth.user` type (wherever it's defined alongside `deck_view_default`) to include `collection_integration_enabled: boolean`.
- Add a toggle to the dashboard / settings page next to the existing default-view and default-sort toggles. Label and help text framed positively ("Track collection on decks" or similar — pin the copy when sketching). Posts to `/collection-integration` on change.

**i18n:** new keys for the toggle label, help text, and update flash message.

**Tests:**
- Feature test: the POST endpoint persists the flag.
- Type-check, lint, smoke test on staging.

---

## Phase 2 — Extensions

Phase 2 fills in the corners. Each step is self-contained and can ship independently of the others.

### Step 2.1 — Per-card "assign physical copy" picker ✅ shipped

**Frontend:** `resources/app/pages/Deck/Modals/DeckCardAssignStackModal.vue`
- Mirror the shape of `DeckCardSwitchPrintingModal.vue`.
- Lists the user's owned card_stacks for this deck card's `default_card_id`, with their container badge.
- Replace-style: selecting a stack assigns it (and unassigns any previously-assigned stack for this deck_card).
- Footer "Clear assignment" button only renders when there's a current assignment to clear.

**Backend:**
- New endpoint: `PATCH /api/decks/{deck}/cards/{deckCard}/assigned-stacks` → `DeckCardController::updateAssignedStacks`.
- New endpoint: `GET /api/decks/{deck}/cards/{deckCard}/assignable-stacks` → `DeckCardController::assignableStacks`. One batched join for stack rows + their current pivot/claim status; reuses `ShowDeckCardPrintingsRequest` for the owner check.
- New `UpdateDeckCardAssignedStacksRequest` — owner gate, ownership/printing/no-foreign-claim guards in `withValidator`.
- New service `app/Services/DeckCardAssignmentService.php`. Single static `replaceAssignedStack(DeckCard, ?CardStack)`: atomic detach + (oversized stacks split via `CardStackService::splitStack` so only `quantity` copies carry the pivot) + attach. Mode-C stays sticky on clear, by design.

**UI wiring:** `DeckCardActionsMenu.vue` — new menu item "Assign physical copy" rendered only when `collectionMode === 'C'`. PATCHes the endpoint then `router.reload({ only: ['cards'] })` so the row's collection-status badge refreshes. New `collection-mode` prop drilled through `CardViewText.vue` and `CardViewImage.vue`.

**i18n:** keys under `pages.deck.assign_stack.*` (title, link, loading/error/empty states, currently-assigned badge, claimed-by-other-deck badge, errors). German + English.

**Tests:** `tests/Feature/DeckCardAssignmentServiceTest.php` — 5 SQLite cases covering first-attach, replace, clear (null), oversized-stack auto-split, exact-size no-split. Smoke-tested on staging.

---

### Step 2.2 — Mode B implicit display ✅ shipped

Different render path from mode C — coverage-icon-based with a tooltip carrying the full breakdown.

**Service additions:**
- `DeckCollectionStatusService::implicitStatusForDeck(Deck $deck): array` — keyed by `deck_card_id`, value is `{ in_deckbox: int, elsewhere: int, missing: int }`. One batched query over `card_stacks` matching the deck's printings, partitioned in PHP by whether `container_id` matches `decks.container_id`. Counts at face value (oversized stacks count fully); wrong-printing copies excluded.

**Controller wiring:** `DecksController::show()` — when `mode === 'B'` *and* `deck.container_id !== null`, inject the implicit-status map per card. Without an anchor the per-card payload stays null so the badges silent themselves; the deck still resolves to mode B at the menu/wizard layer (so the planned→built wizard fires correctly — that's where the user picks a container).

**Frontend:** new `resources/app/components/Deck/CollectionImplicitBadge.vue`. Single storage icon, three colour states using the existing `c.$state` map (success / warning / error) — success when fully covered with all copies in the deck's deckbox, warning when fully covered but some copies sit elsewhere, error when copies are missing. Tooltip phrasing branches on count shape (5 cases: all-here / mixed / all-elsewhere / partial-with-missing / all-missing). Same `inline | corner` variants as `CollectionStatusBadge` so it slots into both card views.

**Types:** `CollectionImplicitStatus` interface added to `types/deckPage.ts`; new `collection_implicit_status: CollectionImplicitStatus | null` on `DeckCardRow`.

**i18n:** `pages.deck.collection_implicit_status.{all_in_deckbox, in_deckbox_and_elsewhere, all_elsewhere, partial_with_missing, all_missing}` in `de.json` + `en.json`.

**Tests:** 6 new SQLite cases in `DeckCollectionStatusServiceTest` (count partitioning, unsorted-as-elsewhere, all-missing, wrong-printing-not-counted, oversized-counted-at-face-value), plus an updated controller test asserting the live payload (mode B emits the implicit status; no-container deck stays mode B with null implicit status).

**Smoke-test fallout:** found that the actions menu's "Set to finished" path uses `collectionMode === 'A'` to decide direct-patch vs wizard. The original 2.2 mode-A fallback for "no container_id" broke this — a planned deck with stacks but no container couldn't reach the wizard. Reverted the fallback in `effectiveMode` and moved the gating to the controller layer (badges silent, mode still B); rolled the missing "set container on a built deck" UI into 2.3.

---

### Step 2.3 — Mode-aware gating audit ✅ shipped

Two parts: an audit sweep over the existing collection-aware elements, plus the deck-edit container picker rolled in from 2.2 smoke-testing.

**Audit findings (all already gated correctly — no code changes needed):**
- ✅ `CardViewText` / `CardViewImage` — only render the mode-C status badge or the mode-B implicit badge when their respective gates are true; mode A is silent.
- ✅ `DeckCardActionsMenu` — gates "Assign physical copy" on `collectionMode === 'C'`; mode A and B never see the entry.
- ✅ `DeckActionsMenu.onSetBuilt()` — direct-PATCHes the state in mode A (no wizard); modes B and C redirect to `/decks/{deck}/finalize`.
- ✅ `DecksController::show` — skips `statusForDeck` when not mode C and `implicitStatusForDeck` when not mode B (or no anchor). Lightweight modal-context queries (`master_switch_enabled`, `has_stacks`, `has_container`, `claimed_count`) always run for owners — required for the modal even in mode A so the "why" recap can phrase what's blocking.
- N/A — "Move to deck's deckbox?" hint isn't implemented yet; nothing to gate.

**Deliberate exception:** `CollectionModeBadge` renders for *all* modes including A — the modal is the explainer/entry point, and silencing the badge in mode A would hide the only path to the modal that explains why the deck is silent.

**Container picker on `CreateEditDeckPage.vue`:**
- `DecksController::edit` ships a `containers` prop (deckbox-flagged, deckboxes sorted to the top — same shape the finalize wizard already uses) and `existingDeck.container_id` so the picker pre-selects what's already set.
- `DecksController::update` validates `container_id` (nullable, must exist in `containers` and belong to the requesting user via `Rule::exists('containers', 'id')->where('user_id', …)`) and persists it on the deck. Empty string clears.
- `CreateEditDeckPage.vue` renders an edit-only `<MonoSelect>` next to the visibility radio. Deckbox-typed entries get a translated "Recommended" suffix; non-deckbox containers stay clean. "— No deckbox —" placeholder clears the binding. Hidden input on the wrapping `<Form>` so submission is unchanged.
- New `form.fields.deck_container{,_hint,_unset,_deckbox_hint}` keys in `de.json` + `en.json`.

**Tests:** `tests/Feature/DeckUpdateContainerTest.php` — 4 SQLite cases covering set / change / clear / foreign-container rejection. The audit items themselves are already covered by the per-step controller / view tests shipped in 1.3 / 1.4 / 2.1 / 2.2.

**Definition of done for 2.3:**
- Every mode-A path is silent at the badge layer; mode-B and mode-C paths each render only their own UI.
- Users can add, change, or clear a deck's deckbox from the deck edit form without resorting to tinker.
- A foreign container UUID submitted via curl/dev-tools is rejected at the FormRequest layer.

---

### Step 2.4 — "Bought new" checkbox in the finalize wizard

Extend the finalize wizard so users who just bought missing copies can fold that into the same submit instead of leaving the wizard, adding card stacks in the collection page, and coming back. Per-row checkbox renders on **every** row — including rows with available stacks (e.g. user owns 3, just bought 1 more) and rows with none (e.g. card not yet in collection).

**Wizard payload extension:**
- Add `buy_new` to the form: `Record<deck_card_id, boolean>`, default false.
- Submit shape becomes `{ assignments, buy_new, container_id }`.

**Stack creation rules** (per row, when `buy_new === true`):
- Compute the *uncovered* slot count: `quantity - sum(amount of stacks chosen in assignments[row])`.
- If uncovered ≤ 0, the checkbox is a no-op (covered by the existing assignment); skip.
- Otherwise create one new card_stack with:
  - `default_card_id` = the deck card's printing.
  - `amount` = the uncovered slot count.
  - `language` / `finish` defaults: `En` / `Nonfoil` (no per-row overrides — keep the wizard quick).
  - `condition` = null.
  - `container_id` = the deckbox the wizard's bottom dropdown picks, falling back to null (unsorted).
- Attach the new stack via the pivot to the deck card (so the row renders `claimed_for_this_deck` after redirect).

**No deck_card auto-split**: when "buy new" fully covers the row, there's no leftover. When it's combined with a partial assignment, the new stack pads to full coverage so the deck_card row stays a single row.

**FormRequest:** extend `FinalizeDeckRequest::rules()` with `buy_new: nullable array`, `buy_new.*: boolean`.

**Service:** extend `DeckFinalizeService::persistAssignments` (or split into a new `BuyNewAndClaim` helper if it gets too dense — judgement call when wired up). Stack creation goes through `CardStackService::addToCollection` so the merge-into-existing-stack logic stays consistent (if the user happens to already have a matching unsorted stack, the new amount merges rather than fragmenting the collection).

**Frontend:**
- New row layout adds the checkbox below or beside the dropdown.
- When checked, show a small inline note: "Will create N× <name> in your collection".
- Disable the checkbox when the row is fully covered by an existing assignment (no uncovered slots).
- i18n keys under `pages.deck.finalize.buy_new.*` (label, hint, computed-amount template).

**Edge cases worth deciding before implementing:**
- Foil/etched copies: default to nonfoil; any user that wants foil tracking goes through the collection page. Don't open that can here.
- A deckbox isn't picked: new stacks land unsorted. Acceptable; the user can re-organize later.
- The deck card has zero quantity (shouldn't happen, but be defensive in the service).

**Tests:**
- Service: `buy_new` with no available stacks → creates one stack of full quantity, attaches pivot.
- Service: `buy_new` combined with partial assignment → creates one stack for the remainder, attaches both.
- Service: `buy_new` fully covered by assignment → no-op.
- Service: `buy_new` + `addToCollection` merge — pre-seed an unsorted stack of the same printing, run wizard, assert stack amount went up rather than a duplicate row appearing.
- Feature test: end-to-end submit through `storeFinalize` with mixed rows.

**Definition of done for 2.4:**
- A user can finalize a planned deck where some cards aren't owned yet, check "bought new" for those rows, submit, and end up with new stacks in the collection plus pivot rows attaching them to the deck.
- The checkbox renders for every row including those with full coverage already (visible-but-disabled in that case).
- New stacks land in the chosen deckbox when one is set on the wizard, else unsorted.

---

### Step 2.5 — Collection-side "claimed for deck X" badges

The lifecycle guards in Phase 1 block container moves on claimed stacks with the message "This card stack is claimed by a deck and cannot be moved. Unclaim it first." — but the user has no way to find *which* deck claimed it without hunting through their own deck list. This step surfaces that information on every claimed stack in the collection, with a link to the offending deck so the user can act.

**Service additions:**
- Bulk lookup that maps `card_stack_id → [{ deck_id, deck_name, deck_card_id }, ...]`. Single batched query joining `deck_card_card_stack → deck_cards → decks`. The schema allows N claimers per stack (UX assumes one), so the helper returns a list. Lives on either `DeckCollectionStatusService` (closest cousin) or a new `CardStackClaimService` if it grows.
- Inject the map into the existing collection list endpoints (`CollectionController`, `ContainerController`) so datatable / card-view rows ship with claim data already attached — no per-row N+1.

**Frontend:**
- Small "Reserviert für [DeckName]" badge on every claimed stack, where `[DeckName]` is an `<Link>` to `decks.show` for that deck.
- Multi-claim case (rare but allowed by schema): comma-list, or "Reserviert für DeckA +N more" with a tooltip showing the full list.
- Render in:
  - `Collection/CardStacksDatatable.vue` — new column or row trailer.
  - `Collection/common/CardStackCard.vue` — the card view variant.
  - `Collection/common/CardStackPreviewModal.vue` — so the modal surfaces the claim too.
  - `Collection/CardStack/CardStackPage.vue` — the edit form, explaining why the container picker is locked.
- New i18n keys under `pages.collection.claim_badge.*` (`label`, `multi_label`, `tooltip_multi`).

**Unclaim affordance** *(intentionally split out)*:
- A per-stack "unclaim" button next to the badge is the obvious pairing — let the user free a stack from the collection side without navigating to the deck. Mirror of the *assign* endpoint Phase 2.1 builds.
- Defer to its own step (call it 2.6 when the time comes) so 2.5 can ship as pure visibility first and 2.1's endpoint shape can inform the unclaim API.

**Tests:**
- Service: bulk lookup returns expected shape for stacks with 0 / 1 / N claims.
- Service: query-count smoke (one query for the whole listing, not N+1).
- Feature: collection list endpoint returns the claim data on each stack row.

**Definition of done for 2.5:**
- Every claimed stack in the collection visibly says which deck claims it, with a clickable link to that deck.
- The lifecycle-guard error stays as-is, but the user can now follow the link to fix the cause instead of hunting.

---

### Step 2.6 — Collection-mode badge + modal in the deck header ✅ shipped

The implicit-mode logic is invisible to users — they can't tell whether a deck is in mode A / B / C without reading the docs. This step surfaces the current mode in `DeckHeader` and gives the user explicit control over the two transitions the design has been deferring (B→C promote, C→B clear-all).

**Frontend:**
- New `resources/app/components/Deck/CollectionModeBadge.vue` rendered owner-only in `DeckHeader`'s badge row. Icon + label, tooltip with one-line description. Click opens the modal.
- New `resources/app/pages/Deck/Modals/CollectionModeModal.vue`. Sections:
  - **Current mode**: heading + paragraph explaining what it does. Driven by the badge presentation mode (see "Badge presentation mode" below) so the modal heading matches what the badge advertised.
  - **Why am I in this mode?**: short rule recap (master switch off / no stacks / no container / no claims yet / claims exist) — phrased from the live context prop and the *real* `collectionMode`, not the badge's presentation mode.
  - **Actions**: one button per available transition, gated on the *real* mode:
    - **In A**: link to settings (master switch off) or `/collection/add` (no stacks). Read-only otherwise.
    - **In B**: "Switch to per-copy tracking" — pins `decks.collection_mode = 'C'` without claiming anything. Plus an "Edit deck" link when `container_id` is null. After promotion the per-card "Assign physical copy" menu becomes reachable; row badges flip to mode-C status (no `claimed_for_this_deck` yet — those land via the picker).
    - **In C**: "Clear all collection assignments" — destructive, two-step confirm in-modal. Detaches every pivot row for the deck and nulls the sticky pin so the deck cleanly returns to mode B.

**Badge presentation mode (`collectionBadgeMode`):** the controller derives a sibling mode for the badge's visuals: equals `collectionMode` except when effective mode is B and `container_id` is null, in which case it demotes to A. Without an anchor the per-row implicit badges withhold themselves; the demotion keeps the header badge from advertising "Implicit tracking" while no per-row badges actually render. The real `collectionMode` continues to drive wizard routing in `DeckActionsMenu` and the modal's why-recap + actions.

**Backend:**
- New `app/Services/DeckCollectionModeService.php`:
  - `promoteToExplicit(Deck $deck): void` — sets `collection_mode = 'C'`, no-op if already.
  - `clearAssignments(Deck $deck): void` — atomic detach-all + null the sticky pin.
- New `PromoteDeckCollectionModeRequest` + `ClearDeckCollectionAssignmentsRequest` (owner gates).
- New `DecksController::promoteCollectionMode` (PATCH) + `DecksController::clearCollectionAssignments` (DELETE) returning a `RedirectResponse` with flash. Mirrors the `setVisibility` / `setState` shape.
- Routes (web, owner-gated):
  - `PATCH /decks/{deck}/collection-mode/promote` named `decks.collection-mode.promote`
  - `DELETE /decks/{deck}/collection-mode/assignments` named `decks.collection-mode.clear`

**Controller payload extension:** `DecksController::show` ships two new top-level props alongside `collectionMode`:
- `collectionBadgeMode` — see above.
- `collectionModeContext: { master_switch_enabled, has_stacks, has_container, claimed_count }` — owner-only; lets the modal phrase the "why" from live state and size the C→B confirm copy.

**i18n:** new keys under `pages.deck.collection_mode.*` (badge labels, modal headings, why-recap copy, action button labels, confirm text, flash messages).

**Tests:**
- Service (`DeckCollectionModeServiceTest`, 5 cases): `promoteToExplicit` is a no-op when already C; `clearAssignments` detaches pivots + nulls pin atomically; `clearAssignments` leaves pivots on other decks alone; `clearAssignments` is a no-op for mode A/B.
- Feature (`DeckCollectionModeControllerTest`, 4 cases): both endpoints owner-gated (403 for non-owner), redirect with flash, persist correctly.
- Feature (extended `DeckFinalizeControllerTest`): payload-shape coverage for `collectionBadgeMode` — demotes to A when mode B + no container, equals real mode otherwise.

**Operational gotcha (caught during smoke testing):** Laravel Boost's browser-logs proxy was active on staging and POSTing Vue console warnings to `/_boost/browser-logs`. ModSecurity flagged the warning text (which contained `onClose=fn` from a fragment-rendering child component) as XSS via `libinjection` rule 941100, repeated 403s tripped fail2ban's modsecurity jail. Fix: set `BOOST_ENABLED=false` in staging's `.env` so the proxy route doesn't register. Boost stays usable locally for development.

**Definition of done for 2.6:**
- A header badge tells the user which mode they're in at a glance.
- The modal explains *why* and offers the actionable transitions where they exist.
- B→C promote works without requiring the wizard or any pre-claimed stacks.
- C→B clear-all detaches every pivot for the deck and nulls the sticky pin in a single transaction.
- The "Implicit tracking" badge no longer fires for decks with no container set — it demotes to "Tracking off" for honesty.

---

## Cross-cutting notes

**Naming conventions:**
- Pivot table: `deck_card_card_stack` (alphabetical, Laravel convention).
- Pivot model: not needed (use `BelongsToMany` direct).
- Status enum: not currently planned to live as a PHP enum since it's only consumed at the controller→view boundary as strings. If it grows, promote it.

**Reload patterns when assignments change:**
- Per-card assign (step 2.1): reload `['cards', 'collection_mode']`.
- Wizard submit (step 1.4): full redirect to `decks.show` (state changed, full reload appropriate).
- Container move blocked (step 1.1): handled at the FormRequest layer, no reload concerns.

**Test-suite split.** During Phase 1 implementation the test suite was split into two physical PHPUnit testsuites — `Local` (SQLite, write-heavy fixtures via `RefreshDatabase`) and `Staging` (MariaDB, read-only against real Scryfall data). `composer test` runs `Local`, `composer test:mysql` runs `Staging`. The split exists because `RefreshDatabase` runs `migrate:fresh` on any non-`:memory:` connection — earlier all tests ran together and accidentally wiped the staging DB. See `CLAUDE.md` § Testing and `README.md` § Backend commands for the rules on which directory + skip-guard a future test belongs to.

**What's intentionally left for later:**
- Bulk reconcile button (a deck-level "auto-claim from collection") — not in scope; the wizard covers the planned→finished moment, the per-card picker covers ongoing maintenance.
- Mode B → C upgrade prompt ("would you like to start tracking specific copies?") — implicit upgrade-by-acting is enough for V1.
- Explicit C → B action ("clear all collection assignments") — schema supports it (set `decks.collection_mode = null`), no UI yet. Phase 2.x.
- Sleeved/foil-condition tracking per assignment — see "Out of scope" in the design doc.