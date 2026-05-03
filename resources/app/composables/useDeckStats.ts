import { computed, type ComputedRef } from "vue";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion } from "Types/deckPage.ts";

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
const TYPE_LABELS = ["Creature", "Planeswalker", "Battle", "Artifact", "Enchantment", "Instant", "Sorcery", "Land"] as const;

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
    allowed: Set<Color> | null = null,
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
    typeCounts: ComputedRef<BreakdownBucket[]>;
    categoryCounts: ComputedRef<BreakdownBucket[]>;
    /** Sum of `quantity` across the 99 (excludes commanders + companion). Drives percent computations. */
    totalNonCommanderCards: ComputedRef<number>;
} {
    const totalNonCommanderCards = computed(() =>
        cards().reduce((sum, card) => sum + card.quantity, 0)
    );

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
            total: 0,
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

    const productionPips = computed<ColorPipTally>(() => {
        const tally = emptyTally();
        const allowed = commanderColorIdentity.value;
        for (const card of cards()) {
            tallyProducedManaInto(tally, card.produced_mana, card.quantity, allowed);
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
            percent: (counts[label] / total) * 100,
        }));
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
                percent: ((byId.get(cat.id) ?? 0) / total) * 100,
            }))
            .filter(b => b.count > 0)
            .sort((a, b) => a.label.localeCompare(b.label));
        const uncategorized = byId.get(null) ?? 0;
        if (uncategorized > 0) {
            named.push({
                key: "__uncategorized",
                label: "Uncategorized",
                count: uncategorized,
                percent: (uncategorized / total) * 100,
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
        typeCounts,
        categoryCounts,
        totalNonCommanderCards,
    };
}
