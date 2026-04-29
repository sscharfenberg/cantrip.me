# Collection ↔ Deck Integration — design notes

Discussion captured 2026-04-28; updated 2026-04-29 with wizard sketch and answers to the open questions. Final implementation plan still to follow once the badge style is locked.

## Product positioning

- **Collection organizer first, deck builder second.** That's the framing.
- **But:** the deck builder must work standalone for users who never enter a collection. Every collection-aware UI element needs a "no collection" mode where it simply doesn't render.

## The three-tier user model

Tiers are **per-deck**, not per-user (so a user can have 30 legacy "for fun" decks alongside 2 tournament decks without forcing all of them into the same mode).

Mode is **inferred from data**, not explicitly toggled:

| Mode | Detection | Deck UI behaviour | Planned→finished behaviour |
|---|---|---|---|
| A — no collection | user has zero `card_stacks` | No collection UI on any deck. Pure deck builder. | Just a state change, no wizard. |
| B — implicit (deckbox) | user has stacks, deck has no pivot rows | Per-row info: count of copies in deck's `container_id` (deckbox) vs elsewhere. No per-stack interaction. | Wizard runs. User can skip (deck stays in B) or claim stacks (deck upgrades to C). |
| C — explicit assignment | deck has any pivot rows | Full status taxonomy + per-card "assign physical copy" + per-row status badges. | Wizard runs. Skip is always available — never blocks the transition. |

Mode upgrade B→C is by *acting* (running the wizard or claiming a card from the actions menu) — no "switch mode" button. A→B happens automatically when the user creates their first card stack. C→B is rare; lives in deck settings as "clear all collection assignments."

## Schema decisions

Schema doesn't change between modes — modes just gate which queries run and which UI renders.

- **Drop** `deck_cards.card_stack_id` (single FK, can't model partial coverage). It's currently dormant — assigned nowhere in the codebase, only exposed in the show payload.
- **Add** pivot table `deck_card_card_stack(deck_card_id, card_stack_id)` — many-to-many, no quantity column.
- **Keep** `decks.container_id` as the *physical-world* deckbox association ("this deck lives in this container on my shelf"). When a user assigns a stack, the system can offer "move it to the deck's deckbox?" to align the digital and physical models.

### Stack atomicity

- **Stacks are atomic.** Linking a 4-Bolt stack to a deck means all 4 are claimed. To claim only 3 of 4, the stack splits first.
- **Why:** a `quantity_used` column on the pivot is a constant "did I update both sides" foot-gun. Atomic linkage keeps the data clean and forces an honest "I'm choosing specific cards" gesture.
- **Reuse:** there's already a `split` operation on deck cards (`DeckCardController::split`). Extend the same idea to card_stacks.

### Stack / assignment lifecycle

| Event | Effect on pivot |
|---|---|
| Deck deleted | Cascade pivot removal. |
| Card removed from deck | Pivot row(s) for that deck_card removed. |
| Card_stack split | New sub-stack(s) inherit the assignment. |
| Card_stack moved to a different container | **Not allowed** for claimed stacks. The container move is blocked while the stack has any pivot row. (User must unclaim first.) |
| Card_stack deleted from collection | Cascade pivot removal. |

## Status taxonomy (mode C)

Six raw states, compressed to four colour buckets. Rendering style matches existing legality / game-changer badges: **icon + colour + tooltip**.

| Raw state | Detection | Render |
|---|---|---|
| Claimed for this deck | stack in pivot, `deck_id = current` | Green + icon=check |
| Available (free copy) | stack owned, no pivot row at all | Green + icon=edit |
| Claimed by another deck | stack in pivot, `deck_id = other` | Yellow + icon=swords |
| Wrong printing (any availability) | different `default_card_id` but same `oracle_card_id` | Orange + icon=planned |
| Don't own at all | no stacks for this oracle | Red + icon=money |

Compression rationale: "wrong printing, claimed elsewhere" doesn't justify its own colour — the user mostly cares about the printing mismatch separately from availability (they can swap printing or buy this one regardless).

## Status taxonomy (mode B)

Mode B is simpler — just "X in deckbox / Y elsewhere / Z missing" per row. No per-stack interactions, no pivot.

## UX surfaces

1. **Planned→finished wizard** — primary assignment flow. Runs in modes B and C; never in A. Always skippable. See sketch below.
2. **Per-card "assign physical copy" picker** — extension of the card actions menu, maintenance escape hatch for ongoing swap-ins after the deck is "finished." Reuses the existing printing-picker UI (`DeckCardSwitchPrintingModal`), but lists owned card_stacks for the deck card's `default_card_id` instead of available printings.
3. **Per-row status badge** — the original ask. Single batched join through the pivot, rendered using the icon + colour + tooltip convention.
4. **Container-side hint** — when a user assigns a stack, offer "move this to the deck's deckbox?" to keep digital and physical aligned (without making them the same thing).

## Wizard sketch (planned→finished)

**Form factor: a dedicated page**, not a modal. Per-row dropdowns, deckbox picker, and submit/skip controls add up to too much vertical real estate for a modal — and the wizard is a discrete one-shot workflow, which a routed page expresses more cleanly than a modal layered over the deck view.

**Header:** *"Please assign physical card stacks to finalize the deck"*

**Body — needs vs assignments:**

| Needed for deck | Assign to |
|---|---|
| 4× Swords to Plowshares ICE:54 | Dropdown: `[ContainerType]:[ContainerName]` |
| 2× Path to Exile CON:15 | Dropdown: `[ContainerType]:[ContainerName]` |
| 3× Lightning Bolt LEA:161 | Not available |
| 4× Urza's Saga MH2:259 | 3 available. Dropdown: `[ContainerType]:[ContainerName]`. Auto-split the deck_card into 3× with pivot + 1× without. |

**Optional — set deckbox:**

> Choose container for this deck *(optional)*  
> Dropdown: `[ContainerType]:[ContainerName]`

**Bottom bar:**

- **Submit** → persists the chosen pivot rows + (optional) `decks.container_id`, transitions deck state to "finished."
- **Skip** → no pivot rows written, just transitions state. Mode B users stay in B; mode C users stay in C.

**No autopick.** Assignment is always a deliberate user action. Auto-picking would be confusing and hard to keep aligned with physical reality.

**Wizard scope is exact printings only.** If the user owns a different printing of the same oracle card, the wizard does not surface it or offer an in-line swap — that case is handled by the post-finish "wrong printing" status badge, where the user can decide to swap printing or acquire the printing the deck calls for. Keeps the wizard focused on a single concept: "claim physical copies of these exact printings."

### Partial coverage

When the deck wants 4 of a card and the user owns only 3 of the same printing, the wizard offers to claim the 3 and **auto-splits the deck_card row** into:
- One deck_card row with `quantity = 3`, with pivot rows linking the assigned stacks.
- One deck_card row with `quantity = 1`, no pivot rows (renders as the "don't own" state in the deck list).

The two rows render as **distinct rows in the deck list** — same name and printing, different status badges. No visual collapsing; users can see at a glance "3 covered, 1 missing" as two separate entries.

The user is **never blocked** from transitioning planned→finished by partial coverage — skip is always available, even in mode C. There may be legitimate reasons to finish a deck with missing copies (proxies, paper-decklist-only, in-progress acquisitions).

## Graceful degradation (mode A)

- All ownership-related UI checks the effective mode first and renders nothing in A.
- Don't *advertise* collection features to users who haven't engaged with that side of the app.
- Cleanest signal: `user.card_stacks_count > 0` (or a derived "uses-collection" flag, computed once and cached on the user).

## Out of scope (V1)

- **Sleeved/foil-condition tracking per assignment.** The existing `card_stacks.finish` + `card_stacks.condition` fields cover this at the stack level; not in scope to track at the deck level too.
- **Multi-deck card pools.** One stack backing multiple decks simultaneously. Pivot is many-to-many in schema but UX assumes one stack belongs to at most one deck at a time.
- **Lending/borrowing tracking.** "This copy is loaned to friend X" — out of scope.
- **Proxies.** Out of scope currently; can be modelled later as a card_stack flag.
- **Multiple deckboxes per deck.** Edge case for users who store deck + sideboard in separate physical boxes. `decks.container_id` is one-to-one for now.

## Existing groundwork that already supports this

- `decks.container_id` exists in the schema (Deck model fillable).
- `containers.type = Deckbox` enum case exists.
- `DeckCardController::split` exists — pattern to mirror for splitting card_stacks.
- Visibility / owner-only viewer-mode work already done in the deck show page — no impact, but worth knowing the deck show page is already discriminating between owner and non-owner users.