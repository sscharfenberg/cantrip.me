# Smoketests

Manual verification checklists per shipped PR. Each list is the minimum that needs to pass before declaring the PR done; automated coverage lives in `tests/Feature/`.

## PR 0 — Explicit collection modes

The `decks.collection_mode` column is no longer inferred. It is set explicitly by the deck owner via the deck-header collection-tracking badge, which is now a popover button with three menu entries (A / B / C).

### Pre-deploy

- Production data: any deck rows with `collection_mode IS NULL` must be backfilled before this migration is re-run. The column is now `NOT NULL DEFAULT 'A'`. Suggested backfill rule (mirrors the previous inference):
  ```sql
  -- Pinned-C stays C.
  UPDATE decks SET collection_mode = 'C' WHERE collection_mode IS NULL AND id IN (
      SELECT DISTINCT dc.deck_id FROM deck_cards dc
      JOIN deck_card_card_stack p ON p.deck_card_id = dc.id
  );
  -- Has container → B.
  UPDATE decks SET collection_mode = 'B' WHERE collection_mode IS NULL AND container_id IS NOT NULL;
  -- Everything else → A.
  UPDATE decks SET collection_mode = 'A' WHERE collection_mode IS NULL;
  ```

### Owner flow

- [ ] Open an owned deck. Header shows the collection-tracking badge.
- [ ] Click the badge — a popover opens with three entries (`Tracking off`, `Implicit tracking`, `Explicit tracking`). The current mode is visually selected.
- [ ] Click `Implicit tracking` on a deck currently in `A` — page reloads, badge now shows `Implicit tracking`, success flash appears. DB row shows `collection_mode = 'B'`.
- [ ] Click `Explicit tracking` — badge now shows `Explicit tracking`, DB row shows `collection_mode = 'C'`.
- [ ] With at least one claimed stack attached to the deck, click `Implicit tracking` from `C`. Browser asks for confirmation, message includes the claim count. Confirming cascade-deletes every `deck_card_card_stack` row for this deck and writes `B`. Cancelling leaves both the mode and pivot rows alone.
- [ ] Same as above but `C → A` — also cascade-deletes pivot rows and writes `A`.
- [ ] Re-pick the same mode that's already selected — no DB write, no flash, popover just closes.

### Master switch override

- [ ] Settings → toggle "Collection integration" off. Open a deck. The collection-tracking badge is hidden entirely (no popover trigger). Other deck UI still functions.
- [ ] Toggle the master switch back on. Open the same deck. Badge reappears with the deck's stored mode preserved (whatever was set before).

### Non-owner

- [ ] Visit a public deck owned by someone else. No collection-tracking badge appears. PATCH `/decks/{id}/collection-mode` returns 403 for non-owners.

### Mode A (silent)

- [ ] Set a deck to mode A. The deck show page renders no per-card status badges (neither explicit nor implicit). `BulkClaim` / `UnclaimedCardStacks` entry points (introduced in later PRs) are hidden.

### Mode B (implicit, no container)

- [ ] Set a deck to mode B with no `container_id`. Badge shows `Implicit tracking`. Per-card implicit-status badges stay silent (no anchor). Edit the deck and pick a deckbox container — per-card counts now render.

### Validation

- [ ] PATCH `/decks/{id}/collection-mode` with `mode=Z` returns 422 with a `mode` error. DB row is untouched.

### Automated coverage (delta)

```bash
composer test -- --filter="DeckCollectionStatusServiceTest|DeckCollectionModeServiceTest|DeckCollectionModeControllerTest|DeckFinalizeControllerTest|CardStackUnclaimControllerTest"
```

Should be green on the `Local` (SQLite) suite.

## PR 1 — Free-flip state transitions

Any deck state (`planned` / `built` / `archived`) can transition to any other directly from the deck-actions menu. The finalize wizard no longer drives state changes (the wizard is renamed to BulkClaim in PR 2 and gains its own menu entry).

### Owner flow

- [ ] Open an owned deck currently in `planned`. Deck-actions menu shows two state entries: `Set to finished` and `Set to archived`. (No `Set to planned` because that's the current state.)
- [ ] Click `Set to finished` — page reloads, deck state is now `built`, success flash appears. The finalize wizard is **not** opened.
- [ ] Menu now shows `Set to planned` and `Set to archived`.
- [ ] Click `Set to archived` — state is now `archived`. Menu now shows `Set to planned` and `Set to finished` (i.e. archived decks can move directly to either non-archived state).
- [ ] Click `Set to finished` from the archived state — state moves directly to `built` without passing through `planned`.

### State independence

- [ ] On a deck in mode C with several claimed stacks, flip state planned → built → archived → planned. Pivot rows survive every transition (only the explicit collection-tracking radio cascade-deletes them).

### Non-owner

- [ ] Visit a public deck owned by someone else. The state-transition menu entries are absent. PATCH `/decks/{id}/state` returns 403 for non-owners.

### Automated coverage (delta)

```bash
composer test -- --filter="DeckFinalizeControllerTest"
```

The added `set_state_endpoint_free_flips_between_every_pair_of_states` test walks every (from → to) pair.
