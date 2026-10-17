import { computed, inject, provide, reactive, type ComputedRef, type InjectionKey } from "vue";
import { type LandCandidate, makeFetchResolver } from "@/utils/fetchlandResolver";
import { bestRole, type SourceRole, sourceRoles } from "@/utils/karstenSources";
import type { DeckHighlight, DeckStatsSelection, ManaSource, SpellSources } from "Types/deckPage.ts";

/**
 * Single uppercase WUBRG letter accepted by the color highlight axes.
 * Colorless ({C}) is intentionally excluded — see the type-level comment
 * on `DeckHighlight`.
 */
export type HighlightColor = "W" | "U" | "B" | "R" | "G";

/**
 * Shape the matcher needs from a card. `DeckCardRow`, `DeckCommander`
 * and `DeckCompanion` all satisfy this structurally; commanders /
 * companions omit `category_id`, so the matcher treats the `category`
 * axis as never matching them (they have no user category).
 *
 * `fetch_pattern`, `land_search_pattern`, `is_basic_land`,
 * `is_mdfc_land` and `zone` are optional because commanders /
 * companions don't carry them — only `DeckCardRow` does. The
 * production-axis matcher classifies a card exactly as the stats do
 * (`karstenSources.ts`), resolving both patterns against the deck's
 * other lands (a fetchland, or a Three Visits finding a Bayou).
 */
export interface HighlightableCard {
    cmc: number;
    type_line: string;
    produced_mana: string[] | null;
    mana_cost: (string | null)[];
    category_id?: string | null;
    fetch_pattern?: string | null;
    land_search_pattern?: string | null;
    is_basic_land?: boolean;
    is_mdfc_land?: boolean;
    mana_source?: ManaSource | null;
    /** Deck zone of a deck row; a sideboard card is no source for a picked spell. */
    zone?: string;
}

/** From mana value `fromMv` on, a card counts as `weight` of a source of the selected colors. */
export interface ShareStep {
    weight: number;
    fromMv: number;
}

/**
 * What a card is worth as a source for the selected spell: `weight`
 * toward it — 0 for a card that only counts toward bigger spells — and
 * every step of its worth, lightest first (Cultivate: ¼ from mana value
 * 4, ½ from 5).
 */
export interface ProductionShare {
    weight: number;
    steps: ShareStep[];
}

/**
 * Public surface of the composable. Read-side computeds are what the
 * stats panels consult to render their own selected-bar UI; the
 * setters are what they call on click. `isHighlighted` is the single
 * matcher the card views use to drive the `.highlighted` class.
 */
export interface DeckHighlightApi {
    highlight: ComputedRef<DeckHighlight>;
    hasHighlight: ComputedRef<boolean>;

    selectedManaValue: ComputedRef<number | null>;
    selectedCategory: ComputedRef<DeckStatsSelection | null>;
    selectedColorProduction: ComputedRef<HighlightColor | null>;
    selectedSpellSources: ComputedRef<SpellSources | null>;
    selectedColorConsumption: ComputedRef<HighlightColor | null>;

    setManaValue: (cmc: number | null) => void;
    setCategory: (sel: DeckStatsSelection | null) => void;
    /**
     * Select a production color — the donut's production ring, where
     * every source counts as one pip: any card that is a source of the
     * color highlights. Clears {@link setSpellSources}; the two are one
     * production axis.
     */
    setColorProduction: (color: HighlightColor | null) => void;
    /**
     * Highlight the sources for one spell — the "Mana sources" row a
     * spell was picked for. Priced by Karsten's weights: only cards that
     * count toward a spell of `mv` in any of `colors` highlight, cards
     * that only count toward bigger spells are later sources, and partial
     * sources report a {@link ProductionShare}. Clears the production
     * ring's color and the cost ring's: AND-ing either with it would
     * mostly leave nothing highlighted.
     */
    setSpellSources: (selection: SpellSources | null) => void;
    setColorConsumption: (color: HighlightColor | null) => void;
    clear: () => void;

    isHighlighted: (card: HighlightableCard) => boolean;
    /**
     * True for a card the spell-sources axis leaves unhighlighted only
     * because it comes online too late for the spell — a Cultivate
     * against a three-drop. It does count toward bigger spells, so the
     * card views mark it in a muted style instead of dimming it with the
     * rest. Every other axis still has to match. Never true for the
     * production ring's unpriced selection.
     */
    isLaterSource: (card: HighlightableCard) => boolean;
    /**
     * For a highlighted or later source of the selected spell, how much
     * of a source it counts as (see {@link ProductionShare}) — null for
     * full sources (lands), for cards that are neither, and while no
     * spell is selected: the production ring counts every source as one,
     * so a "¾" there would contradict it.
     */
    productionShare: (card: HighlightableCard) => ProductionShare | null;
}

const KEY: InjectionKey<DeckHighlightApi> = Symbol("DeckHighlight");

/**
 * Match a card's `mana_cost` (per-face strings like `"{1}{W}{W}"`)
 * against a single WUBRG letter. Hybrid (`{W/U}`), phyrexian (`{W/P}`)
 * and twobrid (`{2/W}`) pips all count as consuming that color.
 * Generic / colorless / variable pips (`{1}`, `{C}`, `{X}`) carry no
 * letter and so never match.
 */
const consumesColor = (manaCost: (string | null)[], color: HighlightColor): boolean => {
    const re = new RegExp(`\\{[^}]*${color}[^}]*\\}`);
    return manaCost.some(face => face !== null && re.test(face));
};

const matchesManaValue = (card: HighlightableCard, value: number): boolean => {
    const floored = Math.floor(card.cmc);
    return value === 8 ? floored >= 8 : floored === value;
};

const matchesCategory = (card: HighlightableCard, sel: DeckStatsSelection): boolean => {
    if (sel.kind === "type") return card.type_line.includes(sel.label);
    if (sel.kind === "category") {
        if (card.category_id === undefined) return false;
        return card.category_id === sel.id;
    }
    // subtype: card must include the chosen card type AND its
    // subtypes (right of the em-dash) must include the chosen
    // subtype, with `null` representing the "no subtype" bucket.
    if (!card.type_line.includes(sel.cardType)) return false;
    const right = card.type_line.split(/\s*—\s*/, 2)[1] ?? "";
    const subtypes = right.split(/\s+/).filter(Boolean);
    return sel.subtype === null ? subtypes.length === 0 : subtypes.includes(sel.subtype);
};

const emptyHighlight = (): DeckHighlight => ({
    mv: null,
    category: null,
    colorProduction: null,
    spellSources: null,
    colorConsumption: null
});

/**
 * Called once at the page root (DeckPage). Returns the api so the page
 * can also read it directly (e.g. for the page-level "clear selection"
 * button) without going through a second `inject` call.
 *
 * `getDeckCards`, when provided, lets the production-axis matcher
 * resolve fetchlands deck-aware: a Polluted Delta in a deck with a
 * Watery Grave is highlighted as a producer of both U and B, even
 * though Scryfall's `produced_mana` for fetches is null. Without the
 * getter, fetchlands fall back to their (empty) raw `produced_mana`
 * and stay un-highlighted — same as the pre-fetchland behavior.
 */
export const provideDeckHighlight = (getDeckCards?: () => HighlightableCard[]): DeckHighlightApi => {
    const highlight = reactive<DeckHighlight>(emptyHighlight());

    /**
     * Per-deck pattern resolver, rebuilt every time the deck's cards
     * change. Returns null when there are no fetchlands or land-search
     * spells so the production matcher can short-circuit. Closure cost
     * is bounded by the deck's land count (one bucket build) plus the
     * number of distinct patterns the highlight axis ever asks about
     * (memoized).
     */
    const resolverFor = (deckCards: HighlightableCard[]): ((pattern: string) => string[]) | null => {
        const lands: LandCandidate[] = deckCards
            .filter(c => c.type_line.includes("Land"))
            .map(c => ({
                type_line: c.type_line,
                is_basic_land: c.is_basic_land ?? false,
                produced_mana: c.produced_mana
            }));
        return makeFetchResolver(deckCards, lands);
    };
    const fetchResolver = computed(() => (getDeckCards ? resolverFor(getDeckCards()) : null));
    /**
     * The resolver a picked spell's sources use: like the stats' Karsten
     * tables, a fetch only finds a main-deck land.
     */
    const mainFetchResolver = computed(() =>
        getDeckCards ? resolverFor(getDeckCards().filter(c => c.zone !== "side")) : null
    );

    /** The colors the production axis asks about — the selected spell's, else the ring's one color. */
    const productionColors = (): HighlightColor[] | null => {
        if (highlight.spellSources !== null) return highlight.spellSources.colors;
        return highlight.colorProduction === null ? null : [highlight.colorProduction];
    };

    /**
     * The card's source roles in any of the selected production colors,
     * by the same classification the stats sum — for a picked spell the
     * Karsten tables' classification, which leaves the sideboard out.
     * Empty when the card supplies none of them, or no production axis
     * is set.
     */
    const colorRoles = (card: HighlightableCard): SourceRole[] => {
        const colors = productionColors();
        if (colors === null) return [];
        const priced = highlight.spellSources !== null;
        if (priced && card.zone === "side") return [];
        const resolve = priced ? mainFetchResolver.value : fetchResolver.value;
        return sourceRoles({ ...card, mana_source: card.mana_source ?? null }, resolve).filter(role =>
            role.colors.some(c => (colors as string[]).includes(c))
        );
    };

    /**
     * The role a card counts through toward the selected spell — any
     * role at all for the ring's unpriced selection, so the ring
     * highlights every card behind its number. Null when it does not
     * count by then.
     */
    const pricedRole = (roles: SourceRole[]): SourceRole | null => {
        const colors = productionColors();
        if (colors === null) return null;
        return bestRole(roles, colors, highlight.spellSources?.mv ?? Number.POSITIVE_INFINITY);
    };

    /** A card's worth over mana value, as rising steps: a later role only matters if it weighs more. */
    const shareSteps = (roles: SourceRole[]): ShareStep[] => {
        const steps: ShareStep[] = [];
        for (const role of [...roles].sort((a, b) => a.minMv - b.minMv)) {
            const last = steps[steps.length - 1] as ShareStep | undefined;
            if (last !== undefined && role.weight <= last.weight) continue;
            if (last !== undefined && role.minMv === last.fromMv) {
                last.weight = role.weight;
            } else {
                steps.push({ weight: role.weight, fromMv: role.minMv });
            }
        }
        return steps;
    };

    const hasHighlight = computed(
        () =>
            highlight.mv !== null ||
            highlight.category !== null ||
            highlight.colorProduction !== null ||
            highlight.spellSources !== null ||
            highlight.colorConsumption !== null
    );

    const selectedManaValue = computed<number | null>(() => highlight.mv);
    const selectedCategory = computed<DeckStatsSelection | null>(() => highlight.category);
    const selectedColorProduction = computed<HighlightColor | null>(() => highlight.colorProduction);
    const selectedSpellSources = computed<SpellSources | null>(() => highlight.spellSources);
    const selectedColorConsumption = computed<HighlightColor | null>(() => highlight.colorConsumption);

    const setManaValue = (cmc: number | null): void => {
        highlight.mv = cmc;
    };
    const setCategory = (selection: DeckStatsSelection | null): void => {
        highlight.category = selection;
    };
    const setColorProduction = (color: HighlightColor | null): void => {
        highlight.colorProduction = color;
        highlight.spellSources = null;
    };
    const setSpellSources = (selection: SpellSources | null): void => {
        highlight.spellSources = selection === null ? null : { colors: [...selection.colors], mv: selection.mv };
        if (selection === null) return;
        highlight.colorProduction = null;
        highlight.colorConsumption = null;
    };
    const setColorConsumption = (color: HighlightColor | null): void => {
        highlight.colorConsumption = color;
    };
    const clear = (): void => {
        highlight.mv = null;
        highlight.category = null;
        highlight.colorProduction = null;
        highlight.spellSources = null;
        highlight.colorConsumption = null;
    };

    /** Every set axis except production — the part the two production states share. */
    const matchesOtherAxes = (card: HighlightableCard): boolean => {
        if (highlight.mv !== null && !matchesManaValue(card, highlight.mv)) return false;
        if (highlight.category !== null && !matchesCategory(card, highlight.category)) return false;
        return !(highlight.colorConsumption !== null && !consumesColor(card.mana_cost, highlight.colorConsumption));
    };

    /**
     * AND across every active axis: a card highlights only if it
     * satisfies *every* set axis. With nothing set, returns false so
     * card views render their default (un-highlighted) state.
     */
    const isHighlighted = (card: HighlightableCard): boolean => {
        if (!hasHighlight.value || !matchesOtherAxes(card)) return false;
        return productionColors() === null || pricedRole(colorRoles(card)) !== null;
    };

    const isLaterSource = (card: HighlightableCard): boolean => {
        if (highlight.spellSources === null || !matchesOtherAxes(card)) return false;
        const roles = colorRoles(card);
        return roles.length > 0 && pricedRole(roles) === null;
    };

    const productionShare = (card: HighlightableCard): ProductionShare | null => {
        if (highlight.spellSources === null || !matchesOtherAxes(card)) return null;
        const roles = colorRoles(card);
        if (roles.length === 0) return null;
        const weight = pricedRole(roles)?.weight ?? 0;
        return weight >= 1 ? null : { weight, steps: shareSteps(roles) };
    };

    const api: DeckHighlightApi = {
        highlight: computed(() => ({
            mv: highlight.mv,
            category: highlight.category,
            colorProduction: highlight.colorProduction,
            spellSources: highlight.spellSources,
            colorConsumption: highlight.colorConsumption
        })),
        hasHighlight,
        selectedManaValue,
        selectedCategory,
        selectedColorProduction,
        selectedSpellSources,
        selectedColorConsumption,
        setManaValue,
        setCategory,
        setColorProduction,
        setSpellSources,
        setColorConsumption,
        clear,
        isHighlighted,
        isLaterSource,
        productionShare
    };

    provide(KEY, api);
    return api;
};

/**
 * Inject the highlight api anywhere inside a `provideDeckHighlight()`
 * subtree (stats panels, card views). Throws synchronously if no
 * provider is present so a missing provider never silently degrades to
 * "nothing highlights anything".
 */
export const useDeckHighlight = (): DeckHighlightApi => {
    const api = inject(KEY);
    if (!api) {
        throw new Error("useDeckHighlight: no provideDeckHighlight() ancestor");
    }
    return api;
};
