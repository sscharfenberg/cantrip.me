# Collection ↔ Deck Integration — implementation plan

Companion to `collection-deck-integration.todo.md` (the design doc). The design doc captures *what and why*; this plan captures *how and in what order*. Two phases — phase 1 is the headline MVP, phase 2 fills in the corners.

## Phase 1 — MVP

The minimum that ships the headline value: a deck owner can claim physical copies from their collection at the planned→finished transition, and the deck view surfaces ownership status per card.

### Step 1.1 — Schema + lifecycle

**Migrations** (project convention: edit existing migrations for existing tables; only create new files for genuinely new tables):
- **New** `create_deck_card_card_stack_table.php` — pivot table for the new many-to-many.
  - Columns: `deck_card_id` (uuid FK → `deck_cards.id`, cascade on delete), `card_stack_id` (uuid FK → `card_stacks.id`, cascade on delete), `created_at`.
  - Composite primary key on `(deck_card_id, card_stack_id)`.
- **Edit** `2026_04_04_205106_create_deck_cards_table.php` — remove the dormant `card_stack_id` column + its index from the original migration.
- **Edit** `0001_01_01_000000_create_users_table.php` — add `collection_integration_enabled` (`boolean`, `default true`, `not null`). User-level opt-out toggle for the entire collection-integration feature; default `true` for everyone (the inferred mode keeps users without stacks silent until data exists).

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

### Step 1.2 — Mode detection + status query

**New service:** `app/Services/DeckCollectionStatusService.php`
- `effectiveMode(User $user, Deck $deck): string` — returns `'A' | 'B' | 'C'`.
  - **Early-return:** `if (! $user->collection_integration_enabled) return 'A'`. The user-level opt-out forces mode A regardless of stacks/pivot state.
  - Counts user's `card_stacks` (cached on `$user->loadCount('cardStacks')` for the request).
  - Counts pivot rows for this deck.
  - A: zero stacks; B: stacks but no pivot for this deck; C: pivot rows exist.

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

### Step 1.3 — Per-row status badges (mode C)

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

### Step 1.4 — Wizard at planned→finished (new page)

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

### Step 1.5 — Settings UI for the collection-integration toggle

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

### Step 2.1 — Per-card "assign physical copy" picker

**Frontend:** `resources/app/pages/Deck/Modals/DeckCardAssignStackModal.vue`
- Mirror the shape of `DeckCardSwitchPrintingModal.vue`.
- Lists the user's owned card_stacks for this deck card's `default_card_id`, with their container badge.
- Replace-style: selecting a stack assigns it (and unassigns any previously-assigned stack for this deck_card).

**Backend:**
- New endpoint: `PATCH /api/decks/{deck}/cards/{deckCard}/assigned-stacks` → `DeckCardController::updateAssignedStacks`.
- New `UpdateDeckCardAssignedStacksRequest` for owner check + payload validation.
- Service: replace pivot rows for the deck_card atomically.

**UI wiring:** `DeckCardActionsMenu.vue` — new menu item "Assign physical copy" (mode C only).

**Tests:** unit-ish on the service, smoke on staging.

---

### Step 2.2 — Mode B implicit display

Different render path from mode C — count-based, not status-icon based.

**Service additions:**
- `DeckCollectionStatusService::implicitStatusForDeck(Deck $deck): array` — keyed by `deck_card_id`, value is `{ in_deckbox: int, elsewhere: int, missing: int }`. Counts based on `decks.container_id`.

**Controller wiring:** `DecksController::show()` — when mode === `'B'`, inject the implicit-status map alongside the deck card payload.

**Frontend:** small per-row count component (different from `CollectionStatusBadge`). Renders compact text like `[3 here · 1 elsewhere]` or similar — TBD when I sketch the row layout.

**Tests:** service-level test for the count math.

---

### Step 2.3 — Mode-aware gating audit

Most gating happens inline as steps 1.3, 1.4, 2.1, 2.2 are built (each component checks `collection_mode` at creation). This step is the audit sweep — walk every collection-aware element and verify the mode-A path is silent.

**Audit checklist:**
- DeckPage / CardViewText / CardViewImage — all collection UI hidden when mode === `'A'`.
- DeckCardActionsMenu — "Assign physical copy" item hidden in modes A and B.
- Deck state-transition (planned→finished) — mode A transitions directly without redirect to wizard.
- Deck show payload — controller doesn't compute status data when mode === `'A'` (avoid unnecessary queries).
- "Move to deck's deckbox?" container hint — only after a successful assignment, only in mode C.

**Tests:** check controller payload shape per mode (3 feature tests). UI smoke per mode.

---

## Cross-cutting notes

**Migration safety:** Step 1.1's migration drops a column. Run on staging first, verify no production data referenced it (we know it's dormant, but worth one final `SELECT COUNT(*) WHERE card_stack_id IS NOT NULL` before commit).

**Naming conventions:**
- Pivot table: `deck_card_card_stack` (alphabetical, Laravel convention).
- Pivot model: not needed (use `BelongsToMany` direct).
- Status enum: not currently planned to live as a PHP enum since it's only consumed at the controller→view boundary as strings. If it grows, promote it.

**Reload patterns when assignments change:**
- Per-card assign (step 2.1): reload `['cards', 'collection_mode']`.
- Wizard submit (step 1.4): full redirect to `decks.show` (state changed, full reload appropriate).
- Container move blocked (step 1.1): handled at the FormRequest layer, no reload concerns.

**What's intentionally left for later:**
- Bulk reconcile button (a deck-level "auto-claim from collection") — not in scope; the wizard covers the planned→finished moment, the per-card picker covers ongoing maintenance.
- Mode B → C upgrade prompt ("would you like to start tracking specific copies?") — implicit upgrade-by-acting is enough for V1.
- Sleeved/foil-condition tracking per assignment — see "Out of scope" in the design doc.

**Definition of done for Phase 1:**
- A user with a non-empty collection can hit "Set to finished" on a planned deck, get the wizard page, claim some/all needed copies, submit, and see the per-row status badges on the deck show page after.
- A user with no collection sees the same deck builder behaviour as before — no regressions.
- All FK cascades verified; container moves blocked for claimed stacks.