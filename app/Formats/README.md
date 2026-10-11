# FormatProfile

Construction rules for Magic: The Gathering formats, expressed as PHP classes.

## Two orthogonal layers

Deckbuilding in a given format is governed by two separate questions:

1. **Is this card in the pool?** — legality / banned list.
2. **Can this card slot into my deck?** — deck size, copy limits, singleton,
   commander, color identity, companion placement.

These layers are independent: Brawl and Commander share the same construction
rules (100-card singleton, color identity, one commander) but draw from
completely different pools. Standard and Modern share the same construction
rules (60/15/4) but draw from different pools.

Mixing the two layers is how format code turns into a tangled mess. We keep
them strictly separated.

### Layer 1 — Pool (data)

Card legality is **data**, not code. The `legalities` pivot table is populated
from Scryfall's bulk data and holds one row per `(oracle_card, format)` pair
with a `CardLegality` status (`legal`, `banned`, `restricted`, `not_legal`).

Use `OracleCard::legalIn($format)` to filter by pool. There is no
`PauperProfile`, no `ModernProfile`, no `BrawlProfile` — these formats differ
only in their pool, and the pivot already answers that.

```php
OracleCard::query()
    ->legalIn(CardFormat::Pauper)
    ->where('name', 'like', "%{$term}%")
    ->limit(20)
    ->get();
```

The `FormatProfile::isInPool()` method is an **overlay hook** for pool rules
that a pivot cannot express — Canadian Highlander points, house bans, custom
cube lists. Default implementation returns `true` and trusts the pivot.

### Layer 2 — Construction rules (code)

Everything else — min/max deck size, max copies, singleton, commander
requirements, color identity, signature spells, companion placement — lives
in a `FormatProfile` subclass.

## Why six classes instead of twenty-one

`CardFormat` has 21 cases, but most of them share rules with another format
and only differ by pool. After collapsing duplicates we are left with:

| Profile               | Formats covered                                                                 |
| --------------------- | ------------------------------------------------------------------------------- |
| `ConstructedProfile`  | Standard, Pioneer, Modern, Legacy, Vintage, Pauper, Penny, Premodern, Oldschool, Alchemy, Historic, Timeless, Future |
| `CommanderProfile`    | Commander, Duel, Brawl, PauperCommander, Predh                                  |
| `StandardBrawlProfile` | StandardBrawl (extends Commander with 60-card deck)                            |
| `OathbreakerProfile`  | Oathbreaker (extends Commander, 60 cards, signature spell)                      |
| `GladiatorProfile`    | Gladiator (100 singleton, no commander)                                         |

The mapping lives in `CardFormat::rules()` as a `match` expression. Adding a
new Scryfall format is usually a one-line addition to that match — or zero
lines if it's just another constructed pool (the `default` arm catches it).

## canAddCopy is stateless

`FormatProfile::canAddCopy()` deliberately does not query the database. The
caller passes in the current copy count:

```php
$profile = $deck->format->rules();
$result = $profile->canAddCopy(
    card: $card,
    currentCopies: $existingCopies,
);

if (! $result->allowed) {
    return back()->withErrors(['card' => $result->reason->value]);
}
```

This keeps the profile pure, easy to unit test, and lets the caller batch DB
lookups once per request instead of once per card. Returns an immutable
`AddCopyResult` value object — `allowed()` or `denied($reason)` where
`$reason` is an `AddCopyFailure` enum case.

The deck size is not checked at all. Building past `maxDeckSize()` and cutting
down afterwards is a common way to build, so adding is never refused for it —
`DeckValidator` reports the oversized deck as a `deck_size_max` violation
instead.

Color identity is not yet checked inside `canAddCopy()` — that needs the
commander context and will be handled by the DeckService layer calling the
profile.

## Frontend consumption

The Vue frontend never switches on format names. Instead, the backend ships
the profile via `toArray()` as a capability bag:

```json
{
  "format": "commander",
  "minDeckSize": 100,
  "maxDeckSize": 100,
  "maxSideboardSize": 0,
  "maxCopies": 1,
  "isSingleton": true,
  "requiresCommander": true,
  "maxCommanders": 2,
  "enforcesColorIdentity": true,
  "hasSignatureSpell": false,
  "companionPlacement": "outside"
}
```

Vue templates switch on the flags:

```vue
<CommanderPicker v-if="profile.requiresCommander" />
<SignatureSpellPicker v-if="profile.hasSignatureSpell" />
<CompanionSlot v-if="profile.companionPlacement === 'outside'" />
```

Adding a new rule is a three-step change: add the method to `FormatProfile`,
override it in the subclass that differs, add it to `toArray()`. The
frontend sees it on the next Inertia response without any TypeScript churn
beyond widening the capability type.

## Files

```
app/Formats/
├── Capabilities/
│   ├── AddCopyFailure.php      # enum of denial reasons
│   ├── AddCopyResult.php       # readonly value object
│   └── CompanionPlacement.php  # enum: Sideboard | Outside
├── FormatProfile.php           # abstract base with constructed defaults
├── ConstructedProfile.php      # marker subclass (no overrides)
├── CommanderProfile.php        # 100 singleton + commander + CI
├── StandardBrawlProfile.php    # 60-card Commander variant
├── OathbreakerProfile.php      # 60-card + signature spell
├── GladiatorProfile.php        # 100 singleton, no commander
└── README.md
```