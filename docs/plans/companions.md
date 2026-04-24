# Companion Support — Implementation Plan

Four phases, each shippable independently. PR 1 is a prerequisite that unlocks the rest.

## Locked decisions

- **Severity**: validations are errors only — no warnings, no severity field.
- **Legality panel placement**: collapsible section in the deck header. Hidden entirely when there are no violations.
- **Lutri's "banned as companion" in Commander**: hardcoded in `CommanderProfile`. Not worth an import pipeline change for one card.
- **Umori**: no stored chosen type. The restriction is "all nonland cards share at least one card type" — any shared type satisfies it, so the validator just intersects type sets. No user picker.
- **Companion section placement**: same row as commanders, to the right.
- **Companion slot is always independent of commander slot**. If a companion card is the commander, it is not acting as companion (its restriction does not apply). A separate companion may still be added; its restriction does apply.
- **Companion-as-commander structural rule**: companion oracle card ≠ any commander oracle card on the same deck (simple uniqueness check).

---

## PR 1 — Deck Legality Panel (prerequisite)

**Goal**: surface existing whole-deck validations in the UI. Today the backend knows when a deck is broken; the user never sees it. This creates the surface that companion violations plug into later.

### Backend

- `app/Services/DeckValidator.php` — new service. `validate(Deck $deck): array` returns a list of violations:
  ```
  { type: string, message_key: string, card_ids: string[] }
  ```
- Covers existing rules that have no UI:
  - `pool_legality` — card banned or not-legal in format per `legalities` pivot
  - `copy_limit` — copies of an oracle card exceed `maxCopies` (respecting basic-land and unlimited-copy exemptions)
  - `deck_size_min` / `deck_size_max` — main deck outside bounds
  - `sideboard_size_max` — sideboard too large
  - `color_identity` — Commander: card CI ⊄ combined commanders' CI
- Called in `DecksController::show`, passed through as a top-level Inertia prop `violations`.

### Frontend

- `resources/app/pages/Decks/Deck/DeckLegalityPanel.vue`
- Collapsible header section. Rendered only when `violations.length > 0`.
- Each violation row: localized message + links/scrolls to offending cards.
- `DeckViolation` TS interface in `types/deckPage.ts`.

### Out of scope

Companion anything, blocking state transitions, auto-fix.

---

## PR 2 — Companion Storage + Slot UI (stub validator)

**Goal**: users can add/remove a companion. Format gates and commander CI checks work. The ten restrictions are not yet enforced.

### Migration

Edit the existing `decks` table migration (no new migration file):

- `companion_oracle_card_id` — UUID, nullable, FK to `oracle_cards.id`, `onDelete('set null')`

### Model

- `Deck::companion()` — `belongsTo(OracleCard::class, 'companion_oracle_card_id')`

### Format layer

- `FormatProfile::allowsCompanion(): bool` — default `true`
- `OathbreakerProfile::allowsCompanion()` → `false` (format has no companion mechanic)
- `CommanderProfile::bannedAsCompanion(): array<string>` — returns `['Lutri, the Spellchaser']`. Default on base class: `[]`.
- Both surface on `FormatProfile::toArray()` capability bag.

### Companion roster

- `app/Companions/CompanionRegistry.php` — resolves the 10 companions by **name** (stable across DB reseeds; UUIDs are not).
  - Constant: the 10 names.
  - `all(): Collection<OracleCard>` — eager-loads faces for image + oracle_text.
  - `isCompanion(OracleCard $card): bool`

### API

- `PATCH /api/decks/{deck}/companion` body `{ oracle_card_id }`
- `DELETE /api/decks/{deck}/companion`
- `DeckCompanionController` (`store`, `destroy`).
- `SetDeckCompanionRequest` FormRequest validates:
  - `oracle_card_id` is one of the 10
  - `$deck->format->rules()->allowsCompanion()` is true
  - Not in `bannedAsCompanion`
  - Respects combined commander CI when `enforcesColorIdentity()`
  - Not already a commander on this deck

### Frontend

- `resources/app/pages/Decks/Deck/Companion/CompanionSection.vue` — rendered to the right of the commander section, same row.
  - Empty state: "Add companion" button.
  - Filled state: card image + remove.
- `resources/app/pages/Decks/Deck/Modals/AddCompanionModal.vue` — 2×5 grid of 10 companions. Each tile:
  - Card image
  - Restriction text (static i18n, not yet enforced)
  - Disabled with tooltip when out of CI or banned as companion in format
- Roster delivered via the Inertia `show` response (small payload, no separate endpoint).
- Types: `DeckCompanion` in `types/deckPage.ts`.

### Stub validator

`DeckValidator` sees the companion field but does not yet check its restriction — just acknowledges the slot.

### Out of scope

The 10 restriction predicates, inline per-add warnings.

---

## PR 3 — CompanionProfile + Restriction Enforcement

**Goal**: each companion's deckbuilding restriction actually enforces in the legality panel.

### Shape

- `app/Companions/CompanionProfile.php` (abstract) — `validate(Deck $deck): array<string>` returns offending card IDs.
- Ten subclasses in `app/Companions/`:
  - `GyrudaProfile`, `JeganthaProfile`, `KaheeraProfile`, `KerugaProfile`, `LurrusProfile`, `LutriProfile`, `OboshProfile`, `UmoriProfile`, `YorionProfile`, `ZirdaProfile`
- `CompanionRegistry::profileFor(OracleCard): ?CompanionProfile` returns the right profile by name.

### Restriction summary + implementation complexity

| Companion | Rule | Complexity |
|---|---|---|
| Gyruda | Each card in starting deck has even mana value (or is a land) | Trivial |
| Jegantha | No card has two-or-more of the same colored mana symbol | **Tricky** — parse mana cost; hybrid pips count toward both colors per rules |
| Kaheera | Each creature is Cat / Elemental / Nightmare / Dinosaur / Beast | Medium — parse `type_line` subtypes |
| Keruga | Each nonland card has mana value ≥ 3 | Trivial |
| Lurrus | Each permanent card has mana value ≤ 2 | Trivial — permanent types from `type_line` |
| Lutri | No more than one of each card other than basic lands | Medium — scan deck for duplicates |
| Obosh | Each nonland card has odd mana value | Trivial |
| Umori | All nonland cards share at least one card type | Medium — intersect type sets |
| Yorion | Starting deck ≥ `minDeckSize + 20` | Trivial |
| Zirda | Each permanent card has an activated ability | Medium — scan `oracle_text` for `":"`. False-positive risk (loyalty costs, mana costs inside reminder text) — verify against a known-bad sample |

### Integration

`DeckValidator` asks the registry for the companion's profile and appends its violations as `type: 'companion_restriction'` with a per-companion `message_key`. `canAddCopy()` is not touched — companion rules stay a lint.

### Tests

Unit tests per profile — one positive, one negative each. Extra cases for Jegantha (hybrid pips) and Umori (shared type present vs. absent).

### Out of scope

Per-add inline warnings in the card search modal.

---

## PR 4 — Polish

- `CompanionSection` rendered conditionally on `allowsCompanion()` (hidden entirely in Oathbreaker).
- Lutri's tile in the modal shows "banned as companion in Commander" copy when applicable.
- Add-card flow: soft warning badge when the card being added would violate the current companion's restriction (non-blocking — user may be about to swap companions).
- Commander picker: helper text if the picked commander is one of the 10, clarifying that using a companion as commander does not make it your companion — a separate companion may still be added.