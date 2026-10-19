import { computed, type ComputedRef } from "vue";
import { type LandCandidate, makeFetchResolver } from "@/utils/fetchlandResolver";
import {
    type AutomaticSpell,
    breakdownCard,
    type KarstenColorAnalysis,
    type KarstenCombinedAnalysis,
    type KarstenCommandZoneCheck,
    type KarstenRowPick,
    sourcesNeeded
} from "@/utils/frankKarstenAnalysis";
import { type SourceCard, type SourceRole, sourceRoles, sourceValue } from "@/utils/karstenSources";
import type { HighlightColor } from "Composables/useDeckHighlight.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion } from "Types/deckPage.ts";

// Re-export for callers that already import the Karsten output types
// from this composable — keeps the existing `DeckStatsColors.vue` and
// any future consumers importing from one place if they prefer.
export type { AutomaticSpell, KarstenColorAnalysis, KarstenCombinedAnalysis, KarstenCommandZoneCheck };

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

/** One card's demand on a color (or combo): `need` sources by the turn it is cast at mana value `cmc`. */
interface KarstenRequirement {
    colors: HighlightColor[];
    need: number;
    cmc: number;
    card: string;
    /** The card's oracle id — what a pick names. */
    oracleId: string;
    /** True for a sideboard card: pickable, but never the automatic choice. */
    sideboard: boolean;
}

/** A spell the Karsten tables can price: any deck row, commander or companion. */
interface KarstenSpell {
    oracle_card_id: string;
    name: string;
    cmc: number;
    type_line: string;
    mana_cost: (string | null)[];
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
 *   - Production (the donut's inner ring) counts every source card as
 *     one pip per color it makes, whatever it is and whenever it comes
 *     online. The Karsten "have" weighs the same cards by Karsten's own
 *     rules instead — a Signet is ¾ of a source, and only for spells it
 *     comes online in time for (see `karstenSources.ts`) — so the ring
 *     reads at least as high as each color's "Mana sources" row.
 *   - The Karsten tables are about the deck you draw from: sources are
 *     the main deck, command zone and companion, and the automatic spell
 *     of each row is a main-deck one. A sideboard spell can still be
 *     picked (`picks`), and is then checked against those sources. The
 *     curve, pips and breakdowns count the sideboard as before.
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
    format: () => string,
    /**
     * The spell the user picked per Karsten row, as row key ("W", or a
     * combination like "WU") → oracle id. A pick that no card of the row
     * matches any more is ignored, and the row falls back to automatic.
     */
    picks: () => Readonly<Record<string, string>> = () => ({}),
    /** Which spell a row without a pick shows — see {@link AutomaticSpell}. */
    automaticSpell: () => AutomaticSpell = () => "hardest"
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
    /**
     * The production ring: per color, how many cards make it — one pip
     * per copy, unweighted, so always a whole number.
     */
    productionPips: ComputedRef<ColorPipTally>;
    /**
     * Per-color Karsten analysis. Only colors a main-deck card (or a
     * commander / the companion) consumes appear. Empty array when the
     * deck has no colored costs at all.
     */
    karstenAnalysis: ComputedRef<KarstenColorAnalysis[]>;
    /**
     * Karsten gold-card "combined" requirements. One row per unique
     * color combination demanded by a gold card; empty for monocolor
     * decks or decks with no gold cards.
     */
    karstenCombined: ComputedRef<KarstenCombinedAnalysis[]>;
    /**
     * Per command-zone card, whether the deck can cast it on curve. Empty
     * for a deck without commanders.
     */
    karstenCommandZone: ComputedRef<KarstenCommandZoneCheck[]>;
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
     * Per-deck pattern resolver for fetchlands and land-search spells.
     * Delegates to {@link makeFetchResolver}, which short-circuits to
     * null when the deck has neither, so {@link sources} classifies by
     * raw `produced_mana` alone.
     */
    const resolverFor = (deckCards: DeckCardRow[]): ((pattern: string) => string[]) | null => {
        const lands: LandCandidate[] = deckCards
            .filter(c => isLand(c.type_line))
            .map(c => ({
                type_line: c.type_line,
                is_basic_land: c.is_basic_land,
                produced_mana: c.produced_mana
            }));
        return makeFetchResolver(deckCards, lands);
    };
    const fetchResolver = computed(() => resolverFor(cards()));

    /** The deck rows the Karsten tables draw sources from — everything but the sideboard. */
    const mainCards = computed<DeckCardRow[]>(() => cards().filter(c => c.zone !== "side"));
    /** {@link fetchResolver} for the Karsten sources: a fetch only finds a main-deck land. */
    const mainFetchResolver = computed(() => resolverFor(mainCards.value));

    /**
     * Every card in `deckCards`, the commanders and the companion that
     * supplies colored mana, with the roles Karsten counts it in (see
     * `karstenSources.ts`). Commanders and the companion count like any
     * other card.
     */
    const collectSources = (
        deckCards: DeckCardRow[],
        resolve: ((pattern: string) => string[]) | null
    ): { roles: SourceRole[]; quantity: number }[] => {
        const out: { roles: SourceRole[]; quantity: number }[] = [];
        const push = (card: SourceCard, quantity: number): void => {
            const roles = sourceRoles(card, resolve);
            if (roles.length > 0) out.push({ roles, quantity });
        };
        for (const card of deckCards) push(card, card.quantity);
        for (const cmd of commanders()) push(cmd, 1);
        const cmp = companion();
        if (cmp !== null) push(cmp, 1);
        return out;
    };

    /** The production ring's sources: the whole deck, sideboard included. */
    const sources = computed(() => collectSources(cards(), fetchResolver.value));
    /** The Karsten tables' sources: main deck, command zone and companion. */
    const karstenSources = computed(() => collectSources(mainCards.value, mainFetchResolver.value));

    /**
     * Weighted sources of any of `colors` toward a spell of mana value
     * `mv`, clamped to the commander color identity — the one "have"
     * both Karsten tables read.
     */
    const haveFor = (colors: readonly HighlightColor[], mv: number): number => {
        const ci = commanderColorIdentity.value;
        const usable = ci === null ? colors : colors.filter(c => ci.has(c));
        if (usable.length === 0) return 0;
        let have = 0;
        for (const source of karstenSources.value) {
            have += source.quantity * sourceValue(source.roles, usable, mv);
        }
        return have;
    };

    /**
     * Pick the row a Karsten table shows for one color (or combo) out
     * of every card demanding it, and price each against the sources
     * that count by that card's mana value. A mana rock or ramp spell
     * helps a five-drop but not a two-drop, so "have" is per
     * requirement, and the most demanding card is not necessarily the
     * one the deck falls short on: twelve Plains and eight Signets
     * cover a `{3}{W}{W}` but not a `{W}` one-drop.
     *
     * `hardest` picks the card closest to failing — the largest
     * `need - have`, ties to the larger need; `easiest` the one with the
     * most room to spare — the smallest `need - have`, ties to the
     * smaller need; `cheapest` the lowest mana value, ties to the
     * smaller need (which, at one mana value, means fewer pips). Either
     * way the row names that card and its mana value, so a "have" that
     * leaves out the deck's ramp explains itself. With only lands in the
     * deck "have" is the same for every card, so hardest and easiest are
     * simply the most and least demanding card.
     */
    const bindingRequirement = (
        requirements: readonly KarstenRequirement[],
        choose: AutomaticSpell = "hardest"
    ): { have: number; need: number; short: number; card: string; mv: number } => {
        let chosen: { have: number; need: number; gap: number; card: string; mv: number } | null = null;
        for (const { colors, need, cmc, card } of requirements) {
            const have = haveFor(colors, cmc);
            const gap = need - have;
            if (chosen === null || isBetter(choose, { gap, need, mv: cmc }, chosen)) {
                chosen = { have, need, gap, card, mv: cmc };
            }
        }
        if (chosen === null) return { have: 0, need: 0, short: 0, card: "", mv: 0 };
        return {
            have: chosen.have,
            need: chosen.need,
            short: Math.max(0, chosen.gap),
            card: chosen.card,
            mv: chosen.mv
        };
    };

    /** Whether `candidate` beats `current` as the automatic spell of a row — see {@link bindingRequirement}. */
    const isBetter = (
        choose: AutomaticSpell,
        candidate: { gap: number; need: number; mv: number },
        current: { gap: number; need: number; mv: number }
    ): boolean => {
        if (choose === "cheapest") {
            return candidate.mv < current.mv || (candidate.mv === current.mv && candidate.need < current.need);
        }
        if (choose === "easiest") {
            return candidate.gap < current.gap || (candidate.gap === current.gap && candidate.need < current.need);
        }
        return candidate.gap > current.gap || (candidate.gap === current.gap && candidate.need > current.need);
    };

    /**
     * Build one Karsten row from every card demanding its color (or
     * combo). The automatic spell is the hardest, easiest or cheapest
     * main-deck requirement, per `automaticSpell`; a pick replaces it with the
     * picked card's own requirement (its tighter one, should the card
     * demand the combo twice — gold and hybrid). Null when no main-deck
     * card demands it: a color only the sideboard asks for gets no row.
     */
    const buildRow = (
        key: string,
        requirements: readonly KarstenRequirement[]
    ): (ReturnType<typeof bindingRequirement> & KarstenRowPick) | null => {
        const main = requirements.filter(r => !r.sideboard);
        if (main.length === 0) return null;
        const automatic = bindingRequirement(main, automaticSpell());
        const pick = picks()[key];
        const picked = pick === undefined ? [] : requirements.filter(r => r.oracleId === pick);
        return {
            ...(picked.length > 0 ? bindingRequirement(picked) : automatic),
            key,
            picked: picked.length > 0,
            automatic: { card: automatic.card, mv: automatic.mv },
            candidates: [...new Set(requirements.map(r => r.oracleId))]
        };
    };

    /**
     * Every spell the Karsten tables price, with whether it sits in the
     * sideboard: the deck rows, the commanders and the companion. Lands
     * are filtered by the callers.
     */
    const karstenSpells = (): { spell: KarstenSpell; sideboard: boolean }[] => {
        const cmp = companion();
        return [
            ...cards().map(card => ({ spell: card, sideboard: card.zone === "side" })),
            ...commanders().map(cmd => ({ spell: cmd, sideboard: false })),
            ...(cmp === null ? [] : [{ spell: cmp, sideboard: false }])
        ];
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

    /**
     * The production ring: each card that makes a color counts one pip
     * per copy for it — a Plains, a Signet and a Cultivate alike. The
     * cards are classified as the Karsten tables classify theirs
     * (`karstenSources.ts`), but the ring takes the whole deck,
     * sideboard included ({@link sources}), where the tables take only
     * the main deck ({@link karstenSources}) and weigh each card.
     */
    const productionPips = computed<ColorPipTally>(() => {
        const tally = emptyTally();
        for (const color of productionAllowed.value) {
            for (const source of sources.value) {
                if (source.roles.some(role => role.colors.includes(color))) tally[color] += source.quantity;
            }
        }
        return tally;
    });

    /**
     * Every Karsten requirement one spell makes — one per row it demands:
     *
     *   - **Per color.** For each color X the card has a forced
     *     (non-hybrid) pip of, Karsten's recommendation for
     *     `(cmc, forcedPipsForX)`. A card with ≥2 distinct *forced*
     *     colors (e.g. `{W}{U}` Teferi) bumps each by +1 — Karsten's
     *     hack for the conditional-probability hit when both colors must
     *     appear by the on-curve turn. Pure hybrid cards (e.g. Yorion
     *     `{3}{W/U}{W/U}`) make none.
     *   - **Gold combined.** A card with ≥2 distinct forced colors makes
     *     one for (forced colors), pip count = total forced pips, lookup
     *     at (cmc, totalForced), **+1** per the same hack.
     *   - **Hybrid combined.** Each unique hybrid color set on the card
     *     (e.g. `{W/U}{W/U}` → one set `{W,U}` with count 2) makes one
     *     for (those colors), lookup at (cmc, count), **no +1** — only
     *     one of the colors is needed per pip, so the
     *     conditional-probability hit doesn't apply (Karsten: "you need
     *     to have enough combined sources of either color in the hybrid
     *     cost").
     *
     * A per-color requirement has one color and a combined one two or
     * more, so its WUBRG-ordered colors are the key of its row.
     */
    const requirementsOf = (spell: KarstenSpell, sideboard: boolean, fmt: string): KarstenRequirement[] => {
        const { cmc, name: card, oracle_card_id: oracleId } = spell;
        const { pips, hybridGroups } = breakdownCard(spell.mana_cost, cmc);
        const forcedColors = COLORS.filter(c => pips[c] > 0);
        const isGold = forcedColors.length >= 2;
        const out: KarstenRequirement[] = [];

        for (const color of forcedColors) {
            const base = sourcesNeeded(fmt, cmc, pips[color]);
            if (base === null) continue;
            out.push({ colors: [color], need: isGold ? base + 1 : base, cmc, card, oracleId, sideboard });
        }

        if (isGold) {
            const totalForced = forcedColors.reduce((sum, c) => sum + pips[c], 0);
            const base = sourcesNeeded(fmt, cmc, totalForced);
            if (base !== null) {
                out.push({ colors: forcedColors, need: base + 1, cmc, card, oracleId, sideboard });
            }
        }

        // Group all hybrid pips by their color set so a card with two
        // `{W/U}` symbols is treated as 2 pips of W∪U, not 2 independent
        // 1-pip lookups.
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
            out.push({ colors, need: base, cmc, card, oracleId, sideboard });
        }
        return out;
    };

    /** Requirements grouped by their row's key, in the order they come. */
    const byRow = (requirements: readonly KarstenRequirement[]): Map<string, KarstenRequirement[]> => {
        const rows = new Map<string, KarstenRequirement[]>();
        for (const requirement of requirements) {
            const key = requirement.colors.join("");
            const existing = rows.get(key);
            if (existing) {
                existing.push(requirement);
            } else {
                rows.set(key, [requirement]);
            }
        }
        return rows;
    };

    /** Every requirement every non-land spell of the deck makes — see {@link requirementsOf}. */
    const karstenRequirements = computed<KarstenRequirement[]>(() => {
        const fmt = format();
        return karstenSpells()
            .filter(({ spell }) => !isLand(spell.type_line))
            .flatMap(({ spell, sideboard }) => requirementsOf(spell, sideboard, fmt));
    });

    /**
     * Per-color Karsten analysis: of every non-land card (commanders
     * and companion included) with a forced pip of a color — see
     * {@link requirementsOf} — the row shows one requirement.
     *
     * The "have" side is weighted by Karsten's own counting rules
     * (`karstenSources.ts`) and priced per card by mana value, so the
     * row shown is the binding requirement rather than simply the
     * largest — see {@link bindingRequirement} — unless the user picked
     * a spell ({@link buildRow}). Sideboard cards demand too, so they
     * can be picked, but only main-deck ones are chosen automatically.
     * Sources are clamped to the commander color identity, as
     * production is.
     */
    const karstenAnalysis = computed<KarstenColorAnalysis[]>(() => {
        const rows = byRow(karstenRequirements.value.filter(r => r.colors.length === 1));
        const out: KarstenColorAnalysis[] = [];
        for (const color of COLORS) {
            const row = buildRow(color, rows.get(color) ?? []);
            if (row !== null) out.push({ color, ...row });
        }
        return out;
    });

    /**
     * Karsten "combined" requirements: how many sources of *any* of a
     * set of colors the deck needs — the gold and hybrid requirements of
     * {@link requirementsOf}.
     *
     * Combos are merged across cards (same color set → one row, the
     * binding requirement wins — see {@link bindingRequirement} — or
     * the picked spell, see {@link buildRow}). The `have` side counts
     * each source × quantity once, at its Karsten weight, if it can make
     * any color of the combo (after CI clamping). Sorted by combo size,
     * then WUBRG-lexicographic.
     *
     * Empty when the deck has neither gold nor hybrid demand.
     */
    const karstenCombined = computed<KarstenCombinedAnalysis[]>(() => {
        const rows: KarstenCombinedAnalysis[] = [];
        for (const [key, requirements] of byRow(karstenRequirements.value.filter(r => r.colors.length > 1))) {
            const row = buildRow(key, requirements);
            if (row !== null) rows.push({ colors: requirements[0].colors, ...row });
        }
        return rows.sort((a, b) => {
            if (a.colors.length !== b.colors.length) return a.colors.length - b.colors.length;
            return a.colors.join("").localeCompare(b.colors.join(""));
        });
    });

    /**
     * Whether each command-zone card can be cast on curve: every
     * requirement it makes ({@link requirementsOf}), each priced for it
     * as its row would be were it picked there — a card demanding a
     * combination twice (gold and hybrid) counts its tighter one. Picks
     * and the automatic spell play no part. A card without colored pips
     * (Rograkh's `{0}`) makes no requirement and is on curve — it is still
     * listed, so a partner never looks forgotten.
     */
    const karstenCommandZone = computed<KarstenCommandZoneCheck[]>(() => {
        const fmt = format();
        const checks: KarstenCommandZoneCheck[] = [];
        for (const cmd of commanders()) {
            if (isLand(cmd.type_line)) continue;
            const requirements = [...byRow(requirementsOf(cmd, false, fmt))].map(([key, demands]) => {
                const { have, need, short } = bindingRequirement(demands);
                return { key, colors: demands[0].colors, have, need, short };
            });
            checks.push({
                oracleId: cmd.oracle_card_id,
                card: cmd.name,
                mv: cmd.cmc,
                requirements,
                onCurve: requirements.every(r => r.short === 0)
            });
        }
        return checks;
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
        karstenCommandZone,
        typeCounts,
        subtypeBreakdowns,
        categoryCounts,
        totalNonCommanderCards
    };
}
