# Finish, language and proxy on the deck page

Plan, 2026-10-29. Delete this file once the work has shipped and the permanent
architecture is in `CLAUDE.md`.

## Problem

A deck card has a printing and a quantity, nothing more. Finish, language,
condition and proxy exist only on `card_stacks`. Marking a planned deck's cards
as foil, German or proxy therefore means: "Add all cards to collection" into a
container → open the container → edit every stack there. Every deck-side path
that creates stacks hardcodes `en` / `nonfoil` / no condition / not proxy:

- `DeckBulkAddCollectionService::addAll` ("Add all cards to collection")
- `DeckFinalizeService::claimStacksForDeckCard` ("bought new" on BulkClaim)
- `DecksController::buyUnclaimed` (Unclaimed page, routes through the finalize service)

and the deck page's `claimed_stacks` payload (`CardStackClaimService::claimedStacksForDeck`)
carries only `{id, container_id}`, so the page can neither show nor edit them.

**History:** `deck_cards.finish` and `deck_cards.language` existed once and were
removed in 5b318b1 as dead columns ("finish and language are owned by physical
card_stacks"). That still holds for *claimed* copies. What was missing then is
a job for the columns: they come back as the **plan** for copies that aren't in
the collection yet, and nothing else.

## Decisions (2026-10-29)

1. **One owner at a time.** For each copy of a deck card, exactly one side owns
   finish / language / proxy, and nothing is ever synced:
   - **Unclaimed copy** → the deck card owns them. They are a plan.
   - **Claimed copy** → the claimed stack owns them. They are a fact. The deck
     page reads them live from the stack; the deck card's stored values are not
     read for that copy.
2. **Unclaiming falls back to the stored values.** The deck card's values are
   whatever it last held: set from the deck side, or adopted at claim time
   (see "Handoffs"). Edits made to a claimed copy are not copied back.
3. **Editing on the deck page writes to the owner:** claimed copies → the stack
   (collection side); unclaimed copies → the deck card (deck side).
4. **Deck worth uses foil and etched prices**, per copy, through the same rule
   the collection already uses.
5. **The collection-count badge on `CardFaceImage` splits by finish** — e.g.
   `1 · 1` (nonfoil · foil) with a tooltip spelling it out.

Rejected alternative: keep both sides' values forever and flag a mismatch
("want foil, have nonfoil"), the way `wrong_printing` works for printings.
Every correction made on the collection side would light up a mismatch on the
deck.

## Rules

### Per copy, not per row

A row can be partly claimed: "Assign physical copy" attaches a 2-copy stack to
a 4-copy row without splitting the row (`DeckCardAssignmentService` only splits
*oversized* stacks), and +1 on a fully claimed row adds an unclaimed copy. So:

- the row's **claimed copies** show their stacks' values;
- the row's **unclaimed copies** (`quantity − claimed amount`, floored at 0)
  show the deck card's values.

This is why the columns are `NOT NULL`, not "NULL while claimed": the unclaimed
part of a partly claimed row needs a plan, and a claim can vanish without app
code running (deleting a stack cascades its pivot rows at the database level).

### Handoffs — the only moments data moves

| Moment | Direction | Code |
| --- | --- | --- |
| A stack is **created** for a deck card ("Add all", "bought new", Buy unclaimed) | deck card → new stack: finish, language, proxy (condition stays null) | `DeckBulkAddCollectionService::addAll`, `DeckFinalizeService::claimStacksForDeckCard` |
| An **existing** stack is claimed (BulkClaim pick, Assign physical copy) | stack → deck card, **only** when the claimed copies cover the whole row and agree on all three values | `DeckFinalizeService::claimStacksForDeckCard`, `DeckCardAssignmentService::replaceAssignedStack` |
| A claim ends (any of the unclaim paths, stack deletion, mode C → B/A) | nothing | — |

The adoption guard matters: adopting a 2-copy nonfoil stack onto a 4-copy row
planned as foil would silently re-plan the 2 unclaimed copies. BulkClaim
already splits rows on partial coverage, so there the claimed row adopts and
the leftover row keeps the plan.

### What the values never do

They never affect coverage, availability, the collection status badges, Quick
Add's printing preference, or the "only show printings available in your
collection" filter. A nonfoil copy covers a foil plan. The availability rule
already exists twice (`partitionCopies` in PHP, `constrainToFreePrintings` in
SQL, pinned together by `FreePrintingsConstraintTest`); finish and language
matching would multiply that. The only soft touch allowed: the Assign picker may
list stacks matching the plan first.

### Condition

Not on `deck_cards`. "Near mint" describes a physical card, not a plan. It is
editable only on claimed copies (step 2).

## Step 1 — the plan columns

### Schema

New ALTER migration (prod has real decks — do not edit the create migration):

- `finish` `unsignedTinyInteger`, default `Finish::Nonfoil->value` — same
  encoding as `card_stacks.finish`
- `language` `string(3)`, default `CardLanguage::En->value`
- `proxy` `boolean`, default `false`

Backfill in the same migration: rows whose claimed stacks cover the whole row
and agree on finish, language and proxy take those values (the adoption rule,
applied to existing data). Everything else keeps the defaults. Model casts on
`DeckCard`: `finish` → `Finish`, `language` → `CardLanguage`, `proxy` → bool.

### Every writer carries the values

| Path | Rule |
| --- | --- |
| `DeckCardController::store` (Add Cards, Quick Add) | defaults |
| +1 on an existing row (`updateQuantity`) | unchanged — more of the same |
| `DeckCardController::moveZone` | merge key gains finish, language, proxy; a newly created target row copies the source's values |
| `DeckCardController::split` | new rows copy the source's values (its docblock already claims finish and language — currently stale) |
| `DeckFinalizeService` leftover row on partial coverage | copies the source's values |
| `DeckService` (create deck with commander / partner / signature spell), `DeckCompanionController` | defaults |
| `DeckCardWriter` (CSV + list import) | new optional `finish` / `language` / `proxy` per row, defaults when absent |
| `DeckListParser` | `*F*` → foil, `*E*` → etched, instead of stripping the marker |
| CSV import mappers (`CantripDeckMapper`, `ArchidektDeckMapper`) | read finish / language / proxy columns when present |
| `DeckCsvExportService` | add the three columns; update the header docblock that calls them removed |
| Seeders (`E2ESeeder`, `E2EDeckRefreshSeeder`, `DeckSeeder`) | column defaults suffice; check fixed-id fixtures still insert |

**Printing switch** (`updatePrinting`, the commander and companion twins,
split printing): when the new printing doesn't exist in the row's finish
(`default_cards.finishes` bitmask), fall back to the printing's first available
finish, nonfoil preferred. Finish validation on the deck-side edit (below) uses
the same bitmask check as `UpdateCardStackRequest`.

### Stack creation reads the plan

`DeckBulkAddCollectionService::addAll` and the "bought new" branch of
`DeckFinalizeService::claimStacksForDeckCard` pass the deck card's finish,
language and proxy instead of the literals. `addToCollection` already merges
into an existing stack only when all attributes match, so a foil plan never
merges into a nonfoil stack.

### Deck worth

`DeckWorthService::perDeckTotals`, per row:

```
claimed part   = Σ over claimed stacks: amount × unit price of the stack
unclaimed part = max(quantity − claimed amount, 0) × unit price of the deck card
```

with "unit price" being `ContainerService::unitPriceSql` — generalised to take
the table whose `finish` / `proxy` it reads, so stacks and deck cards share one
CASE (proxy → 0, nonfoil / foil / etched → the matching price column). Claimed
copies beyond the row's quantity (multi-claim anomaly) are capped at quantity,
as the proxy clamp does today. A foil printing without a foil price counts 0 on
both sides — same as the collection, deliberately no fallback.

`StatsService` and the deck list call `perDeckTotals`, so they follow for free.

### Display

Both `CardViewText` and `CardViewImage`, plus the command-zone and companion
blocks: markers reusing the collection's vocabulary — `star` icon for foil,
`texture` for etched, `ProxyBadge`, and the language flag
(`assets/flags/<lang>.svg`) only when not English. Nothing is shown for the
default nonfoil / English / real case, so an untouched deck looks as it does
today.

Payload: each deck card row (and the commander / companion payloads) ships
`copies`, built in one place — a list of groups, each
`{ count, finish, language, proxy, condition|null, stack_id|null }`: one group
per claimed stack, plus one group for the unclaimed remainder when there is
one. The markers render from `copies`, never from the row's own columns. A row
whose groups differ shows counts ("foil 2/4") with a tooltip listing them.

Owner-only details (stack ids, condition) follow the same gate as
`claimed_stacks` today: owner, master switch on. A guest viewing a public deck
sees the deck card's plan values only.

### Unclaimed page + shopping list

`collectUnclaimedRows` ships the plan values; the page shows the markers and
the CSV export gains `finish`, `language`, `proxy` columns — the shopping list
says "foil, German".

### Deck-side editing (unclaimed copies)

New card-menu entry **"Edit copies"** (owner only) in `DeckCardActionsMenu`,
`DeckCommanderActionsMenu` and `DeckCompanionActionsMenu`, opening
`DeckCardCopiesModal`. In step 1 it lists the unclaimed group: finish (only the
printing's available finishes), language, proxy, and an **"apply to N of M
copies"** amount. Applying to fewer than all splits the deck card row
(same mechanics as `split`, same printing, new values).

Endpoint: `PATCH /api/decks/{deck}/cards/{deckCard}/copies`, FormRequest with
`authorize()` (owner).

## Step 2 — editing claimed copies

`DeckCardCopiesModal` gains one group per claimed stack: finish, language,
condition, proxy, and "apply to N of M". Saving writes the stack.

- **Apply to fewer than all copies of a stack:** `CardStackService::splitStack`,
  then edit the split-off part. The split-off part inherits the source's pivot
  rows, so it stays claimed by this deck card (and by any other deck card that
  claimed the source).
- **A stack also claimed by another deck:** editing it changes that deck too —
  it's one physical card. The modal says so ("also in: Deck X"), reusing
  `CardStackClaimService::bulkClaimsForStacks`.
- **No container changes here.** Moving stays on the stack edit page and the
  Unclaim dialog, so the claimed-stack move guard in `UpdateCardStackRequest` is
  not involved.
- Wherever claims exist, as with the deck page's "Unclaim", which is offered in
  any mode (claims can outlive a switch away from mode C only through imports,
  but they are shown, so they are editable). Mode B records no claims, so its
  rows have no claimed groups and edit the plan only.

Same endpoint as step 1, with stack groups added; the request checks each
stack id is pivoted to this deck card.

## Step 3 — owned count by finish on `CardFaceImage`

`CardStackService::ownedAmountsFor` returns, per printing,
`{ nonfoil: int, foil: int, etched: int }` instead of one total (printings the
user owns none of stay absent; guest / master switch off stays an empty map).
Proxies keep counting as owned, as the proxy hint in `lang/*.json` already
promises.

Producers to update (each ships `owned => $map[$id] ?? null`): card search
(`DefaultCardsController`), deck card search (`DeckCardSearchService`), deck
printings (`DeckPrintingsService`), deck tokens (`DecksController::show`),
card-stack edit (`CardStackController`), and both preview endpoints
(`CardStackPreviewController`, `DefaultCardPreviewController`).

Frontend: `DefaultCardImage.owned` and `cardPreview.owned` change type;
`CardFaceImage` renders `nonfoil · foil`, adding etched only when non-zero;
tooltip "1 nonfoil and 1 foil copy in your collection" (pluralised per finish).
`CardPreviewModal` passes the object through.

Independent of steps 1–2; can ship first or in the same PR.

## Bug found while planning

`CardStackService::splitStack` doesn't copy `proxy`: claiming 1 copy of a
4-copy proxy stack (BulkClaim, Assign) creates a split-off stack that is *not*
a proxy, so the deck counts it at full price. Its docblock lists the copied
attributes and omits proxy too. Fix in step 1 (step 2 depends on the split
being faithful), with a test.

## Tests

PHP (`composer test`, SQLite, `RefreshDatabase`):

- migration backfill: agreeing full claim adopts; partial claim, mixed stacks
  and unclaimed rows keep defaults
- `addAll` and "bought new" create stacks with the plan's values; a foil plan
  doesn't merge into a nonfoil stack
- adoption guard: partial claim via Assign leaves the plan untouched
- `moveZone` merge key; `split` copies values; printing switch finish fallback
- `copies` payload: claimed row shows stack values when the deck card
  disagrees (the "one owner" pin); partly claimed row shows both groups;
  guest sees plan only
- `DeckWorthService`: foil plan priced foil; claimed foil stack under a
  nonfoil plan priced foil; proxy plan 0; multi-claim cap
- copies endpoint: owner gate, finish availability, split on "N of M", stack
  not pivoted to this card → 422, split-off keeps pivots
- `splitStack` keeps `proxy`
- `ownedAmountsFor` split by finish; guest / master switch off → empty
- `DeckListParser` fixtures: `*F*` / `*E*`

Vitest: markers in both card views from `copies`; `DeckCardCopiesModal`
(finish options limited to the printing, "N of M" bounds, payload shape);
`CardFaceImage` owned rendering and tooltip. Mutation-check each new spec.

## Out of scope

- Finish / language matching for availability or coverage (see "What the values
  never do").
- An upgrade wishlist ("want foil, own nonfoil") — the rejected alternative.
- Condition as a plan value.

## Progress

- **Step 1 — done.** As planned, plus what review turned up:
  - with the master switch off, "Edit copies" treats every copy as planned, like the deck page; a card whose claims are hidden is re-planned whole only;
  - `moveZone` returns the rows it merged (`merged_ids`) and both rows' copies, so the page no longer guesses which sibling to drop;
  - the assign flows reload `deck`, since a claim reprices the copy at its stack's finish;
  - a command-zone line's `*F*` / `*E*` reaches the commander;
  - `replan` locks the row and treats unchanged values as a no-op.
- **Step 2 — done.** The dialog lists one group per claimed stack, then the plan, and edits the one picked. A stack edit writes the collection (`DeckCardCopiesService::editClaimedStack`); a partial one splits the stack, the split-off part keeps every claim, and a stack edited back folds into its twin (same cards, same claims). Both endpoints hide stacks while the master switch is off; the stack must be the user's and claimed for this card. A stack edit that leaves out `condition` keeps the stack's.
