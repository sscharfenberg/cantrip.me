# Smoketests

Manual verification checklists per shipped PR. Each list is the minimum that needs to pass before declaring the PR done; automated coverage lives in `tests/Feature/`.

## PR 0 — Explicit collection modes

The `decks.collection_mode` column is no longer inferred. It is set explicitly by the deck owner via the deck-header collection-tracking badge, which is now a popover button with three menu entries (A / B / C).

### Pre-deploy

The migration file changed an existing column (`nullable` → `NOT NULL DEFAULT 'A'`). Laravel won't re-run an already-applied migration, so existing databases need a manual one-time SQL pass.

**Order matters:** backfill all NULL rows first, then flip the column. A `NOT NULL` flip on a column that still has NULL rows aborts.

```sql
-- 1. Backfill NULLs per the previous inference rules.
UPDATE decks
   SET collection_mode = 'C'
 WHERE collection_mode IS NULL
   AND id IN (
       SELECT DISTINCT dc.deck_id
         FROM deck_cards dc
         JOIN deck_card_card_stack p ON p.deck_card_id = dc.id
   );

UPDATE decks
   SET collection_mode = 'B'
 WHERE collection_mode IS NULL
   AND container_id IS NOT NULL;

UPDATE decks
   SET collection_mode = 'A'
 WHERE collection_mode IS NULL;

-- 2. Flip the column. Quote the literal — bare `DEFAULT A` makes MariaDB
--    parse `A` as a column reference and fail with "Unknown column 'A'".
ALTER TABLE decks
MODIFY COLUMN collection_mode VARCHAR(1)
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci
    NOT NULL
    DEFAULT 'A';
```

Verify with `SELECT collection_mode, COUNT(*) FROM decks GROUP BY collection_mode;` after step 1 — there should be no NULL bucket. Then run step 2.

### Owner flow

- [x] Open an owned deck. Header shows the collection-tracking badge.
- [x] Click the badge — a popover opens with three entries (`Tracking off`, `Implicit tracking`, `Explicit tracking`). The current mode is visually selected.
- [x] Click `Implicit tracking` on a deck currently in `A` — page reloads, badge now shows `Implicit tracking`, success flash appears. DB row shows `collection_mode = 'B'`.
- [x] Click `Explicit tracking` — badge now shows `Explicit tracking`, DB row shows `collection_mode = 'C'`.
- [x] With at least one claimed stack attached to the deck, click `Implicit tracking` from `C`. Transition is silent — cascade-delete fires server-side, the deck moves to `B`, success flash appears. No prompt (the user chose cascade-delete in the spec, so no per-action friction).
- [x] Same as above but `C → A` — also silent cascade-delete + flash.
- [x] Re-pick the same mode that's already selected — no DB write, no flash, popover just closes.

### Master switch override

- [x] Settings → toggle "Collection integration" off. Open a deck. The collection-tracking badge is hidden entirely (no popover trigger). Other deck UI still functions.
- [x] Toggle the master switch back on. Open the same deck. Badge reappears with the deck's stored mode preserved (whatever was set before).

### Non-owner

- [x] Visit a public deck owned by someone else. No collection-tracking badge appears. PATCH `/decks/{id}/collection-mode` returns 403 for non-owners.

### Mode A (silent)

- [x] Set a deck to mode A. The deck show page renders no per-card status badges (neither explicit nor implicit). `BulkClaim` / `UnclaimedCardStacks` entry points (introduced in later PRs) are hidden.

### Mode B (implicit, no container)

- [x] Set a deck to mode B with no `container_id`. Badge shows `Implicit tracking`. Per-card implicit-status badges stay silent (no anchor). Edit the deck and pick a deckbox container — per-card counts now render.

### Validation

- [x] PATCH `/decks/{id}/collection-mode` with `mode=Z` returns 422 with a `mode` error. DB row is untouched.

### Automated coverage (delta)

```bash
composer test -- --filter="DeckCollectionStatusServiceTest|DeckCollectionModeServiceTest|DeckCollectionModeControllerTest|DeckFinalizeControllerTest|CardStackUnclaimControllerTest"
```

Should be green on the `Local` (SQLite) suite.

## PR 1 — Free-flip state transitions

Any deck state (`planned` / `built` / `archived`) can transition to any other directly from the deck-actions menu. The finalize wizard no longer drives state changes (the wizard is renamed to BulkClaim in PR 2 and gains its own menu entry).

### Owner flow

- [x] Open an owned deck currently in `planned`. Deck-actions menu shows two state entries: `Set to finished` and `Set to archived`. (No `Set to planned` because that's the current state.)
- [x] Click `Set to finished` — page reloads, deck state is now `built`, success flash appears. The finalize wizard is **not** opened.
- [x] Menu now shows `Set to planned` and `Set to archived`.
- [x] Click `Set to archived` — state is now `archived`. Menu now shows `Set to planned` and `Set to finished` (i.e. archived decks can move directly to either non-archived state).
- [x] Click `Set to finished` from the archived state — state moves directly to `built` without passing through `planned`.

### State independence

- [x] On a deck in mode C with several claimed stacks, flip state planned → built → archived → planned. Pivot rows survive every transition (only the explicit collection-tracking radio cascade-deletes them).

### Non-owner

- [x] Visit a public deck owned by someone else. The state-transition menu entries are absent. PATCH `/decks/{id}/state` returns 403 for non-owners.

### Automated coverage (delta)

```bash
composer test -- --filter="DeckBulkClaimControllerTest"
```

The `set_state_endpoint_free_flips_between_every_pair_of_states` test walks every (from → to) pair.

## PR 2 — BulkClaim rename + restructure

The planned→built finalize wizard has been renamed and restructured. Route `/decks/{deck}/finalize` is now `/decks/{deck}/bulk-claim`, the controller methods are `bulkClaim` / `storeBulkClaim`, and the page lives at `resources/app/pages/Deck/BulkClaim/BulkClaimCardsPage.vue`. State transitions are no longer driven by submitting the page (PR 1 decoupled state).

### Owner flow (mode C deck)

- [x] Open an owned mode-C deck with at least one unclaimed card. The deck-actions menu shows a "Claim cards" entry.
- [x] Click "Claim cards" — `/decks/{id}/bulk-claim` opens, breadcrumb shows the deck name + "Claim cards for {name}".
- [x] Header reads "Claim physical card stacks for this deck". There is no "Skip" button; the only primary button is "Claim cards".

### §1 exact printing

- [x] At least one card row appears under "Exact printing available in your collection" when the user owns the same printing as the deck.
- [x] Pick a stack from the dropdown. If the stack's `amount` is less than the deck card's `quantity`, an "Add {N} more copies to my collection" checkbox appears.
- [x] Submit. Pivot rows are written; the deck's state is unchanged (still planned/built/whatever it was).

### §2 different printing

- [x] A card row appears under "A different printing available in your collection" when the user owns an alternate printing.
- [x] The dropdown labels include the printing's `SET:collector#` and amount.
- [x] Submit. `deck_cards.default_card_id` for that row is updated to the picked stack's printing, AND the pivot row is written. After the redirect, the deck view shows the alternate printing AND a green "claimed" badge for that row (NOT `wrong_printing`).

### §3 nothing available

- [x] A card row appears under "Not in your collection" when the user owns nothing of the oracle card.
- [x] The row has only an "Add all {N} copies to my collection" checkbox, no dropdown.
- [x] Tick the checkbox and submit. A new stack of `quantity` copies is minted and claimed. The deck view shows a green "claimed" badge.

### Cross-deck poaching guard

- [x] User has two decks. Deck A has `container_id = deckbox-A`. Deck B has no container_id. A card stack sits in deckbox-A.
- [x] Open BulkClaim for deck B. The stack from deckbox-A does NOT appear in §1/§2 dropdowns — it's physically allocated to deck A and the eligibility filter excludes it.

### Mode gating

- [x] Set a deck to mode A. The "Claim cards" menu entry disappears. Hitting `/decks/{id}/bulk-claim` directly returns 403.
- [x] Set a deck to mode B. Same — entry hidden, direct URL returns 403.
- [x] Set back to mode C. Entry returns.

### Non-owner

- [x] Visit a public deck owned by someone else while logged in. No "Claim cards" entry. Direct GET to `/decks/{id}/bulk-claim` returns 403 (FormRequest authorize).
- [x] Same URL while logged out: 302 → `/login` (auth middleware fires before the FormRequest).

### Empty / fully claimed deck

- [x] Open BulkClaim for a deck where every card is already claimed. The page renders an "Every card in this deck is already claimed." message and the submit button is disabled.

### Automated coverage (delta)

```bash
composer test -- --filter="DeckBulkClaimControllerTest|DeckFinalizeServiceTest"
```

New tests cover §2 printing-swap, mode-C gating, and the cross-deck deckbox filter.

## PR 3 — UnclaimedCardStacks page + menu entry

A new `/decks/{deck}/unclaimed` page lists every uncovered deck slot. Mode B is read-only (the user is expected to physically move cards into the deckbox container); mode C adds per-row "add to collection" checkboxes that mint a stack and claim it on submit. Both modes get a same-page CSV download.

The deck-actions menu surfaces an "Unclaimed cards" entry when coverage is incomplete (modes B/C, owner-only). BulkClaim's submit now redirects to this page so the user immediately sees what's still missing.

### Owner flow (mode C deck with uncovered cards)

- [x] Open an owned mode-C deck where at least one slot is uncovered. The deck-actions menu shows an "Unclaimed cards" entry.
- [x] Click "Unclaimed cards" — `/decks/{id}/unclaimed` opens. The header reads "Unclaimed cards for {name}".
- [x] Each row shows `{unclaimed}× {card name}` plus `SET:collector#`.
- [x] Tick the master checkbox "Add all to my collection". Every row checkbox flips on.
- [x] Tick a specific row. Untick the master. Verify only that row stays checked.
- [x] Submit. A new stack of `unclaimed` size is minted for each ticked row and claimed for the deck. The new stack lands in the deck's `container_id` (or unsorted if none set). Page reloads; the just-claimed rows are gone from the list.

### Owner flow (mode B deck with uncovered cards)

- [ ] Open an owned mode-B deck where the container doesn't cover every card. The "Unclaimed cards" entry shows.
- [ ] Click it. The page is read-only — no checkboxes, no submit button. The intro copy reads "Cards that aren't in this deck's container yet."
- [ ] Each row shows the unclaimed count + card name + edition + collector#.

### CSV download

- [ ] On the unclaimed page (mode B OR mode C), click "Download CSV of unclaimed cards". A CSV downloads named `{deck-slug}-unclaimed-{YYYY-MM-DD}.csv`.
- [ ] The CSV has header row `name,edition,collector_number,qty,scryfall_id,zone,role` and one row per uncovered slot.

### Menu gating

- [ ] Mode A deck: "Unclaimed cards" entry is hidden.
- [ ] Mode B / mode C deck with 100% coverage: entry is hidden.
- [ ] Public deck owned by someone else while logged in: entry is hidden; direct GET to `/decks/{id}/unclaimed` returns 403. Logged out: 302 → `/login` (auth middleware).
- [ ] Mode A direct GET to `/decks/{id}/unclaimed` returns 403.
- [ ] Mode B direct POST to `/decks/{id}/unclaimed/buy` returns 403 (only mode C may mint+claim).

### Redirect from BulkClaim

- [ ] On a mode-C deck, open BulkClaim, claim a card, submit. The redirect lands on `/decks/{id}/unclaimed`, not on the deck show page.
- [ ] If every card was just claimed, the unclaimed page shows "Every card in this deck is covered."

### Automated coverage (delta)

```bash
composer test -- --filter="DeckUnclaimedControllerTest"
```

Covers mode-B / mode-C coverage math, the mint+claim endpoint, the CSV stream, and 403 paths for non-owners / mode A.
