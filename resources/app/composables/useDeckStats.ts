import { computed, type ComputedRef } from "vue";
import { type LandCandidate, makeFetchResolver } from "@/utils/fetchlandResolver";
import {
    breakdownCard,
    type KarstenColorAnalysis,
    type KarstenCombinedAnalysis,
    sourcesNeeded
} from "@/utils/frankKarstenAnalysis";
import type { HighlightColor } from "Composables/useDeckHighlight.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion } from "Types/deckPage.ts";

// Re-export for callers that already import the Karsten output types
// from this composable — keeps the existing `DeckStatsColors.vue` and
// any future consumers importing from one place if they prefer.
export type { KarstenColorAnalysis, KarstenCombinedAnalysis };

// ---------------------------------------------------------------------------
// Output shapes
// ---------------------------------------------------------------------------

/** A single column in the mana curve. The bucket with `cmc: 8` represents "8 or more". */
export interface ManaCurveBucket {
    cmc: number;
    /** Cards in this bucket that resolve as permanents (creature/artifact/enchantment/planeswalker/battle). */
    permanents: number;
    /** Cards in this bucket that don't resolve permanently (instant/sorcery). */
    spells: number;
    total: number;
}

/**
 * Per-color pip tally. Same shape on both pies so the pie chart component
 * takes one prop type — generic and colorless mana are intentionally
 * excluded (matches Archidekt / Moxfield convention; nothing interesting
 * about counting `{1}`s).
 */
export interface ColorPipTally {
    W: number;
    U: number;
    B: number;
    R: number;
    G: number;
}

/**
 * One row in either the type-breakdown chart or the category-breakdown
 * chart. Same shape so the bar chart component is type-agnostic — the
 * caller toggles which getter feeds it.
 */
export interface BreakdownBucket {
    /** Stable key for v-for / chart segment ids. Type label or category id. */
    key: string;
    label: string;
    count: number;
    /** `count / totalNonCommanderCards * 100`. Sums to >100% for type breakdown (additive — Artifact Creature counts in both buckets). */
    percent: number;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/** Mana cost token regex — matches the parsing in `ManaCost.vue`. */
const SYMBOL_REGEX = /\{([^}]+)}/g;

/** Colors we tally. Anything else (`C`, `S`, digits, `X`, `P`) is ignored. */
const COLORS = ["W", "U", "B", "R", "G"] as const;
type Color = (typeof COLORS)[number];

/**
 * Card types we surface in the type breakdown, in display order. Matches
 * Archidekt / Moxfield. Tribal isn't included — supertype only, no card
 * has just "Tribal" as its main category. Add if needed.
 */
const TYPE_LABELS = [
    "Creature",
    "Planeswalker",
    "Battle",
    "Artifact",
    "Enchantment",
    "Instant",
    "Sorcery",
    "Land"
] as const;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const emptyTally = (): ColorPipTally => ({ W: 0, U: 0, B: 0, R: 0, G: 0 });

/**
 * Parse a Scryfall mana cost string and add its colored pips into the
 * tally, weighted by `quantity`. Hybrid (`{W/U}` → +1 W AND +1 U),
 * Phyrexian (`{W/P}` → +1 W), monocolored hybrid (`{2/W}` → +1 W) all
 * fall out of the same "split on /, keep color letters" rule. Generic
 * (`{1}`, `{X}`), colorless (`{C}`), and snow (`{S}`) are skipped.
 */
function tallyManaCostInto(tally: ColorPipTally, cost: string | null, quantity: number): void {
    if (!cost) return;
    for (const match of cost.matchAll(SYMBOL_REGEX)) {
        for (const part of match[1].split("/")) {
            if ((COLORS as readonly string[]).includes(part)) {
                tally[part as Color] += quantity;
            }
        }
    }
}

/**
 * Add a card's `produced_mana` array into the tally, weighted by quantity.
 * Filters out colorless (`C`) for symmetry with the cost side. When
 * `allowed` is provided (commander-like formats), also drops colors
 * outside the deck's commander color identity — a 5-color mana producer
 * in a Boros deck physically can't produce blue/black/green there.
 */
function tallyProducedManaInto(
    tally: ColorPipTally,
    produced: string[] | null,
    quantity: number,
    allowed: Set<Color> | null = null
): void {
    if (!produced) return;
    for (const color of produced) {
        if (!(COLORS as readonly string[]).includes(color)) continue;
        if (allowed !== null && !allowed.has(color as Color)) continue;
        tally[color as Color] += quantity;
    }
}

/**
 * Tally one card's mana cost across all of its faces (split / MDFC /
 * transform). The deck card carries one `mana_cost` entry per face;
 * each one is parsed independently.
 */
function tallyCardCostInto(tally: ColorPipTally, faces: (string | null)[], quantity: number): void {
    for (const face of faces) {
        tallyManaCostInto(tally, face, quantity);
    }
}

/** True when the front-face type line includes "Land". */
function isLand(typeLine: string): boolean {
    return typeLine.includes("Land");
}

/**
 * Split a Scryfall type line into its card types (left of the em-dash)
 * and subtypes (right). Supertypes and types share the left side and
 * aren't separated here — the caller filters by `TYPE_LABELS` to get
 * the card types it cares about.
 *
 *   "Legendary Creature — Human Soldier" → { types: ["Creature"], subtypes: ["Human", "Soldier"] }
 *   "Artifact"                            → { types: ["Artifact"], subtypes: [] }
 *   "Artifact Creature — Construct"       → { types: ["Artifact", "Creature"], subtypes: ["Construct"] }
 */
function parseTypeLine(typeLine: string): { types: string[]; subtypes: string[] } {
    const [leftRaw = "", rightRaw = ""] = typeLine.split(/\s*—\s*/, 2);
    const leftTokens = leftRaw.split(/\s+/).filter(Boolean);
    const types = leftTokens.filter((t): t is (typeof TYPE_LABELS)[number] =>
        (TYPE_LABELS as readonly string[]).includes(t)
    );
    const subtypes = rightRaw.split(/\s+/).filter(Boolean);
    return { types, subtypes };
}

/** True when the front-face type line resolves as a permanent (creature/artifact/enchantment/planeswalker/battle). */
function isPermanent(typeLine: string): boolean {
    return /Creature|Artifact|Enchantment|Planeswalker|Battle/.test(typeLine);
}

// ---------------------------------------------------------------------------
// Composable
// ---------------------------------------------------------------------------

/**
 * Reactive deck statistics derived entirely from props that DeckPage already
 * has — no server round-trip. Each output is its own `computed`, so changing
 * one input re-runs only the affected slice.
 *
 * Inputs are getters (not refs) so callers can pass props verbatim:
 *
 *   const { manaCurve, costPips } = useDeckStats(
 *       () => props.cards,
 *       () => props.commanders,
 *       () => props.companion,
 *       () => props.categories,
 *   );
 *
 * Behavior baked in:
 *   - Lands excluded from the mana curve.
 *   - Commanders contribute to the curve, costPips, and productionPips.
 *     Companion contributes to costPips and productionPips only — matches
 *     Moxfield/Archidekt convention of excluding companions from the
 *     curve (they're cast from outside the deck).
 *   - Type breakdown and category breakdown count the 99 only —
 *     commanders + companion excluded.
 *   - In commander-like formats (commanders.length > 0), production is
 *     clamped to the combined commander color identity. A 5-color mana
 *     producer (Birds of Paradise) in a Boros deck only contributes to
 *     R and W production — it can't legally produce off-CI colors there.
 *   - Quantity-weighted everywhere.
 *   - Hybrid mana counts both colors. `{W/U}` = +1 W AND +1 U. So the
 *     pip total can exceed the deck's CMC sum — by design.
 *   - Type breakdown is additive (no priority). An Artifact Creature
 *     counts in both buckets. Sum of percents > 100% is expected.
 */
export function useDeckStats(
    cards: () => DeckCardRow[],
    commanders: () => DeckCommander[],
    companion: () => DeckCompanion | null,
    categories: () => DeckCategoryRow[],
    format: () => string
): {
    manaCurve: ComputedRef<ManaCurveBucket[]>;
    /**
     * Average mana value across non-land cards. Includes commanders
     * (mirrors the curve's inclusion rule) and is quantity-weighted
     * (4 Sol Rings count as 4 contributions of cmc=1). Returns 0 for
     * an empty deck.
     */
    averageManaValue: ComputedRef<number>;
    costPips: ComputedRef<ColorPipTally>;
    productionPips: ComputedRef<ColorPipTally>;
    /**
     * Per-color Karsten analysis. Only colors with a non-zero
     * requirement (i.e. at least one card consumes them) appear. Empty
     * array when the deck has no colored costs at all.
     */
    karstenAnalysis: ComputedRef<KarstenColorAnalysis[]>;
    /**
     * Karsten gold-card "combined" requirements. One row per unique
     * color combination demanded by a gold card; empty for monocolor
     * decks or decks with no gold cards.
     */
    karstenCombined: ComputedRef<KarstenCombinedAnalysis[]>;
    typeCounts: ComputedRef<BreakdownBucket[]>;
    /**
     * Subtype breakdown per card type. Keys are the card type labels
     * from `TYPE_LABELS`; values are subtype buckets sorted by count
     * descending (with a "No subtype" entry merged in by count, not
     * pinned). Includes commanders + companion in both numerator and
     * denominator. Card types with zero cards are omitted entirely
     * (so the second-dropdown picker can iterate over keys).
     *
     * Percent denominator is `count / cards-of-this-type` — the user
     * has already filtered by type, so the natural intuition is "of
     * my creatures, X% are humans".
     */
    subtypeBreakdowns: ComputedRef<Record<string, BreakdownBucket[]>>;
    categoryCounts: ComputedRef<BreakdownBucket[]>;
    /** Sum of `quantity` across the 99 (excludes commanders + companion). Drives percent computations. */
    totalNonCommanderCards: ComputedRef<number>;
} {
    const totalNonCommanderCards = computed(() => cards().reduce((sum, card) => sum + card.quantity, 0));

    /**
     * Combined color identity of all commanders, as a Set. Returns null
     * when the deck has no commanders (e.g. constructed) — used as the
     * "no clamping" sentinel for production tallying.
     */
    const commanderColorIdentity = computed<Set<Color> | null>(() => {
        if (commanders().length === 0) return null;
        const set = new Set<Color>();
        for (const cmd of commanders()) {
            if (!cmd.color_identity) continue;
            for (const letter of cmd.color_identity) {
                if ((COLORS as readonly string[]).includes(letter)) {
                    set.add(letter as Color);
                }
            }
        }
        return set;
    });

    const manaCurve = computed<ManaCurveBucket[]>(() => {
        const buckets: ManaCurveBucket[] = Array.from({ length: 9 }, (_, cmc) => ({
            cmc,
            permanents: 0,
            spells: 0,
            total: 0
        }));
        const addToBucket = (cmc: number, typeLine: string, quantity: number) => {
            const bucket = buckets[Math.min(Math.floor(cmc), 8)];
            if (isPermanent(typeLine)) {
                bucket.permanents += quantity;
            } else {
                bucket.spells += quantity;
            }
            bucket.total += quantity;
        };
        for (const card of cards()) {
            if (isLand(card.type_line)) continue;
            addToBucket(card.cmc, card.type_line, card.quantity);
        }
        for (const cmd of commanders()) {
            addToBucket(cmd.cmc, cmd.type_line, 1);
        }
        return buckets;
    });

    const costPips = computed<ColorPipTally>(() => {
        const tally = emptyTally();
        for (const card of cards()) {
            if (isLand(card.type_line)) continue;
            tallyCardCostInto(tally, card.mana_cost, card.quantity);
        }
        for (const cmd of commanders()) {
            tallyCardCostInto(tally, cmd.mana_cost, 1);
        }
        const cmp = companion();
        if (cmp !== null) {
            tallyCardCostInto(tally, cmp.mana_cost, 1);
        }
        return tally;
    });

    /**
     * Per-deck fetchland resolver. Delegates to
     * {@link makeFetchResolver}, which short-circuits to null when
     * the deck has no fetchlands so `effectiveProduced` can fall
     * straight back to raw `produced_mana`.
     */
    const fetchResolver = computed<((pattern: string) => string[]) | null>(() => {
        const all = cards();
        const lands: LandCandidate[] = all
            .filter(c => isLand(c.type_line))
            .map(c => ({
                type_line: c.type_line,
                is_basic_land: c.is_basic_land,
                produced_mana: c.produced_mana
            }));
        return makeFetchResolver(all, lands);
    });

    /**
     * Effective produced_mana for a single deck card. Non-fetchlands
     * pass through with no work. Fetchlands route through
     * {@link fetchResolver}, which itself short-circuits to null
     * when the deck has no fetchlands at all.
     */
    const effectiveProduced = (card: DeckCardRow): string[] | null => {
        if (!card.fetch_pattern) return card.produced_mana;
        const resolver = fetchResolver.value;
        return resolver === null ? card.produced_mana : resolver(card.fetch_pattern);
    };

    /**
     * Colors the deck actually *requires* — i.e. the colors a Mox
     * Diamond / Birds of Paradise / fetchland could usefully produce
     * for this build. A color is "useful" iff some non-land card in
     * the deck has a forced (non-hybrid) pip in that color.
     *
     * Pure hybrid (`{G/U}{G}{G}` Endurance, `{R/W}{R/W}{R/W}` Boros
     * Reckoner) does NOT mark the alternative color as useful — the
     * cost can always be paid with the forced side, so the alternative
     * is flexibility, not a requirement.
     *
     * Edge case: a deck with NO forced colored pips anywhere (pure
     * hybrid Yorion / Lurrus build) falls back to the hybrid-colors
     * union from {@link costPips}, so the production donut doesn't
     * collapse to empty.
     *
     * Independent of {@link commanderColorIdentity}: the legality
     * clamp is a separate concern and {@link productionPips} applies
     * both, intersecting them.
     */
    const usedColors = computed<Set<Color>>(() => {
        const forced = new Set<Color>();
        const considerCard = (faces: (string | null)[], cmc: number): void => {
            const { pips } = breakdownCard(faces, cmc);
            for (const c of COLORS) {
                if (pips[c] > 0) forced.add(c);
            }
        };
        for (const card of cards()) {
            if (isLand(card.type_line)) continue;
            considerCard(card.mana_cost, card.cmc);
        }
        for (const cmd of commanders()) {
            considerCard(cmd.mana_cost, cmd.cmc);
        }
        const cmp = companion();
        if (cmp !== null) {
            considerCard(cmp.mana_cost, cmp.cmc);
        }
        if (forced.size > 0) return forced;

        // Pure-hybrid fallback: no forced colored pips anywhere. Use
        // the union of hybrid-pip colors so the donut still has
        // something to show.
        const fromHybrid = new Set<Color>();
        const cp = costPips.value;
        for (const c of COLORS) {
            if (cp[c] > 0) fromHybrid.add(c);
        }
        return fromHybrid;
    });

    /**
     * Colors that the production donut should display: the deck's
     * required colors, intersected with the commander color identity
     * legality clamp (when present). A 5-color rock in a Boros deck
     * still has its UBG slices clamped by CI; a Mox Diamond in a
     * non-commander Lands deck has its UBR slices clamped by usedColors.
     */
    const productionAllowed = computed<Set<Color>>(() => {
        const used = usedColors.value;
        const ci = commanderColorIdentity.value;
        if (ci === null) return used;
        const intersection = new Set<Color>();
        for (const c of used) {
            if (ci.has(c)) intersection.add(c);
        }
        return intersection;
    });

    const productionPips = computed<ColorPipTally>(() => {
        const tally = emptyTally();
        const allowed = productionAllowed.value;
        for (const card of cards()) {
            tallyProducedManaInto(tally, effectiveProduced(card), card.quantity, allowed);
        }
        for (const cmd of commanders()) {
            tallyProducedManaInto(tally, cmd.produced_mana, 1, allowed);
        }
        const cmp = companion();
        if (cmp !== null) {
            tallyProducedManaInto(tally, cmp.produced_mana, 1, allowed);
        }
        return tally;
    });

    /**
     * Per-color Karsten analysis. For each color X, scans every
     * non-land card (including commanders + companion), and for each
     * card with a forced (non-hybrid) X pip, looks up Karsten's
     * recommended source count for `(cmc, forcedPipsForX)` — the
     * deck's requirement is the max across all such cards.
     *
     * Gold-card adjustment: when a card has ≥2 distinct *forced*
     * colors in its cost (e.g. `{W}{U}` Teferi), each per-color
     * requirement is bumped by +1 before being maxed in (Karsten's
     * hack for the conditional-probability hit when both colors must
     * appear by the on-curve turn).
     *
     * Pure hybrid cards (e.g. Yorion `{3}{W/U}{W/U}`) contribute
     * nothing here — they have no forced pips. Their requirement is
     * surfaced as a hybrid-combined row in `karstenCombined` instead.
     *
     * The "have" side reuses `productionPips`, which already counts a
     * card once per produced color × quantity (a 5-color dork in a
     * Boros deck contributes only to W and R, since `allowed` clamps
     * to the commander color identity — the same convention applies
     * here).
     */
    const karstenAnalysis = computed<KarstenColorAnalysis[]>(() => {
        const fmt = format();
        const need: Record<HighlightColor, number> = { W: 0, U: 0, B: 0, R: 0, G: 0 };

        const considerCard = (faces: (string | null)[], cmc: number, typeLine: string): void => {
            if (isLand(typeLine)) return;
            const { pips } = breakdownCard(faces, cmc);
            const forcedColors = COLORS.filter(c => pips[c] > 0);
            const isGold = forcedColors.length >= 2;
            for (const color of forcedColors) {
                const base = sourcesNeeded(fmt, cmc, pips[color]);
                if (base === null) continue;
                const required = isGold ? base + 1 : base;
                if (required > need[color]) {
                    need[color] = required;
                }
            }
        };

        for (const card of cards()) {
            considerCard(card.mana_cost, card.cmc, card.type_line);
        }
        for (const cmd of commanders()) {
            considerCard(cmd.mana_cost, cmd.cmc, cmd.type_line);
        }
        const cmp = companion();
        if (cmp !== null) {
            considerCard(cmp.mana_cost, cmp.cmc, cmp.type_line);
        }

        const have = productionPips.value;
        return COLORS.filter(color => need[color] > 0).map(color => ({
            color,
            have: have[color],
            need: need[color],
            short: Math.max(0, need[color] - have[color])
        }));
    });

    /**
     * Karsten "combined" requirements: how many sources of *any* of a
     * set of colors the deck needs. Two flavors feed the same output:
     *
     *   - **Gold combined.** A card with ≥2 distinct forced colors
     *     contributes a combo of (forced colors), pip count = total
     *     forced pips, lookup at (cmc, totalForced), **+1** per
     *     Karsten's gold-card hack.
     *   - **Hybrid combined.** Each unique hybrid color set on the
     *     card (e.g. `{W/U}{W/U}` → one set `{W,U}` with count 2)
     *     contributes a combo of (those colors), lookup at (cmc,
     *     count), **no +1** — only one of the colors is needed per
     *     pip, so the conditional-probability hit doesn't apply
     *     (Karsten: "you need to have enough combined sources of
     *     either color in the hybrid cost").
     *
     * Combos are merged across cards (same color set → one row, max
     * `need` wins). The `have` side counts each producer × quantity
     * once if its `produced_mana` (after CI clamping) intersects the
     * combo. Sorted by combo size, then WUBRG-lexicographic.
     *
     * Empty when the deck has neither gold nor hybrid demand.
     */
    const karstenCombined = computed<KarstenCombinedAnalysis[]>(() => {
        const fmt = format();
        const allowed = commanderColorIdentity.value;

        // Per-combo max requirement, keyed by the WUBRG-ordered combo string.
        const reqByCombo = new Map<string, { colors: HighlightColor[]; need: number }>();

        const upsert = (colors: HighlightColor[], required: number): void => {
            const key = colors.join("");
            const existing = reqByCombo.get(key);
            if (!existing || required > existing.need) {
                reqByCombo.set(key, { colors, need: required });
            }
        };

        const considerCard = (faces: (string | null)[], cmc: number, typeLine: string): void => {
            if (isLand(typeLine)) return;
            const { pips, hybridGroups } = breakdownCard(faces, cmc);
            const forcedColors = COLORS.filter(c => pips[c] > 0);

            // Gold-card combined (≥2 forced colors → +1 hack).
            if (forcedColors.length >= 2) {
                const totalForced = forcedColors.reduce((sum, c) => sum + pips[c], 0);
                const base = sourcesNeeded(fmt, cmc, totalForced);
                if (base !== null) {
                    upsert(forcedColors, base + 1);
                }
            }

            // Hybrid combined (one row per unique hybrid color set).
            // Group all hybrid pips by their color set so a card with
            // two `{W/U}` symbols is treated as 2 pips of W∪U, not 2
            // independent 1-pip lookups.
            const groupedHybrid = new Map<string, { colors: HighlightColor[]; count: number }>();
            for (const group of hybridGroups) {
                const key = group.join("");
                const existing = groupedHybrid.get(key);
                if (existing) {
                    existing.count += 1;
                } else {
                    groupedHybrid.set(key, { colors: group, count: 1 });
                }
            }
            for (const { colors, count } of groupedHybrid.values()) {
                const base = sourcesNeeded(fmt, cmc, count);
                if (base === null) continue;
                upsert(colors, base);
            }
        };

        for (const card of cards()) {
            considerCard(card.mana_cost, card.cmc, card.type_line);
        }
        for (const cmd of commanders()) {
            considerCard(cmd.mana_cost, cmd.cmc, cmd.type_line);
        }
        const cmp = companion();
        if (cmp !== null) {
            considerCard(cmp.mana_cost, cmp.cmc, cmp.type_line);
        }

        if (reqByCombo.size === 0) return [];

        // "Have" side: count each producer × quantity once if its
        // produced_mana (after CI clamping) intersects the combo.
        // Done as a nested loop because the combo count is tiny
        // (rarely > 3) and the producer list is small.
        const producers: { produced: string[]; qty: number }[] = [];
        const pushProducer = (produced: string[] | null, qty: number) => {
            if (!produced) return;
            const filtered = produced.filter(
                c => (COLORS as readonly string[]).includes(c) && (allowed === null || allowed.has(c as Color))
            );
            if (filtered.length === 0) return;
            producers.push({ produced: filtered, qty });
        };
        for (const card of cards()) pushProducer(effectiveProduced(card), card.quantity);
        for (const cmd of commanders()) pushProducer(cmd.produced_mana, 1);
        if (cmp !== null) pushProducer(cmp.produced_mana, 1);

        const haveFor = (combo: HighlightColor[]): number => {
            const comboSet = new Set<string>(combo);
            let have = 0;
            for (const p of producers) {
                if (p.produced.some(c => comboSet.has(c))) {
                    have += p.qty;
                }
            }
            return have;
        };

        return [...reqByCombo.values()]
            .map(({ colors, need }) => {
                const have = haveFor(colors);
                return { colors, have, need, short: Math.max(0, need - have) };
            })
            .sort((a, b) => {
                if (a.colors.length !== b.colors.length) return a.colors.length - b.colors.length;
                return a.colors.join("").localeCompare(b.colors.join(""));
            });
    });

    const typeCounts = computed<BreakdownBucket[]>(() => {
        const counts: Record<string, number> = Object.fromEntries(TYPE_LABELS.map(t => [t, 0]));
        for (const card of cards()) {
            for (const label of TYPE_LABELS) {
                if (card.type_line.includes(label)) {
                    counts[label] += card.quantity;
                }
            }
        }
        const total = totalNonCommanderCards.value || 1;
        return TYPE_LABELS.map(label => ({
            key: label,
            label,
            count: counts[label],
            percent: (counts[label] / total) * 100
        }))
            .filter(b => b.count > 0)
            .sort((a, b) => b.count - a.count);
    });

    const subtypeBreakdowns = computed<Record<string, BreakdownBucket[]>>(() => {
        type Acc = { typeTotal: number; subtypes: Map<string, number>; noSubtype: number };
        const accByType: Record<string, Acc> = Object.fromEntries(
            TYPE_LABELS.map(label => [label, { typeTotal: 0, subtypes: new Map(), noSubtype: 0 }])
        );

        const tally = (typeLine: string, quantity: number): void => {
            const { types, subtypes } = parseTypeLine(typeLine);
            for (const cardType of types) {
                const acc = accByType[cardType];
                if (!acc) continue;
                acc.typeTotal += quantity;
                if (subtypes.length === 0) {
                    acc.noSubtype += quantity;
                } else {
                    for (const st of subtypes) {
                        acc.subtypes.set(st, (acc.subtypes.get(st) ?? 0) + quantity);
                    }
                }
            }
        };

        for (const card of cards()) tally(card.type_line, card.quantity);
        for (const cmd of commanders()) tally(cmd.type_line, 1);
        const cmp = companion();
        if (cmp !== null) tally(cmp.type_line, 1);

        const result: Record<string, BreakdownBucket[]> = {};
        for (const cardType of TYPE_LABELS) {
            const acc = accByType[cardType];
            if (acc.typeTotal === 0) continue;
            const buckets: BreakdownBucket[] = [...acc.subtypes.entries()].map(([label, count]) => ({
                key: label,
                label,
                count,
                percent: (count / acc.typeTotal) * 100
            }));
            if (acc.noSubtype > 0) {
                buckets.push({
                    key: "__no_subtype",
                    label: "__no_subtype",
                    count: acc.noSubtype,
                    percent: (acc.noSubtype / acc.typeTotal) * 100
                });
            }
            buckets.sort((a, b) => b.count - a.count);
            result[cardType] = buckets;
        }
        return result;
    });

    const categoryCounts = computed<BreakdownBucket[]>(() => {
        const byId: Map<string | null, number> = new Map();
        for (const card of cards()) {
            const key = card.category_id;
            byId.set(key, (byId.get(key) ?? 0) + card.quantity);
        }
        const total = totalNonCommanderCards.value || 1;
        const named = categories()
            .map(cat => ({
                key: cat.id,
                label: cat.name,
                count: byId.get(cat.id) ?? 0,
                percent: ((byId.get(cat.id) ?? 0) / total) * 100
            }))
            .filter(b => b.count > 0)
            .sort((a, b) => a.label.localeCompare(b.label));
        const uncategorized = byId.get(null) ?? 0;
        if (uncategorized > 0) {
            named.push({
                key: "__uncategorized",
                label: "Uncategorized",
                count: uncategorized,
                percent: (uncategorized / total) * 100
            });
        }
        return named;
    });

    const averageManaValue = computed<number>(() => {
        let totalCmc = 0;
        let totalCount = 0;
        for (const card of cards()) {
            if (isLand(card.type_line)) continue;
            totalCmc += card.cmc * card.quantity;
            totalCount += card.quantity;
        }
        for (const cmd of commanders()) {
            totalCmc += cmd.cmc;
            totalCount += 1;
        }
        return totalCount === 0 ? 0 : totalCmc / totalCount;
    });

    return {
        manaCurve,
        averageManaValue,
        costPips,
        productionPips,
        karstenAnalysis,
        karstenCombined,
        typeCounts,
        subtypeBreakdowns,
        categoryCounts,
        totalNonCommanderCards
    };
}
