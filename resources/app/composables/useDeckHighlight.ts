import { computed, inject, provide, reactive, type ComputedRef, type InjectionKey } from "vue";
import type { DeckHighlight, DeckStatsSelection } from "Types/deckPage.ts";

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
 */
export interface HighlightableCard {
    cmc: number;
    type_line: string;
    produced_mana: string[] | null;
    mana_cost: (string | null)[];
    category_id?: string | null;
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
    selectedColorConsumption: ComputedRef<HighlightColor | null>;

    setManaValue: (cmc: number | null) => void;
    setCategory: (sel: DeckStatsSelection | null) => void;
    setColorProduction: (color: HighlightColor | null) => void;
    setColorConsumption: (color: HighlightColor | null) => void;
    clear: () => void;

    isHighlighted: (card: HighlightableCard) => boolean;
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
    colorConsumption: null
});

/**
 * Called once at the page root (DeckPage). Returns the api so the page
 * can also read it directly (e.g. for the page-level "clear selection"
 * button) without going through a second `inject` call.
 */
export const provideDeckHighlight = (): DeckHighlightApi => {
    const highlight = reactive<DeckHighlight>(emptyHighlight());

    const hasHighlight = computed(
        () =>
            highlight.mv !== null ||
            highlight.category !== null ||
            highlight.colorProduction !== null ||
            highlight.colorConsumption !== null
    );

    const selectedManaValue = computed<number | null>(() => highlight.mv);
    const selectedCategory = computed<DeckStatsSelection | null>(() => highlight.category);
    const selectedColorProduction = computed<HighlightColor | null>(() => highlight.colorProduction);
    const selectedColorConsumption = computed<HighlightColor | null>(() => highlight.colorConsumption);

    const setManaValue = (cmc: number | null): void => {
        highlight.mv = cmc;
    };
    const setCategory = (selection: DeckStatsSelection | null): void => {
        highlight.category = selection;
    };
    const setColorProduction = (color: HighlightColor | null): void => {
        highlight.colorProduction = color;
    };
    const setColorConsumption = (color: HighlightColor | null): void => {
        highlight.colorConsumption = color;
    };
    const clear = (): void => {
        highlight.mv = null;
        highlight.category = null;
        highlight.colorProduction = null;
        highlight.colorConsumption = null;
    };

    /**
     * AND across every active axis: a card highlights only if it
     * satisfies *every* set axis. With nothing set, returns false so
     * card views render their default (un-highlighted) state.
     */
    const isHighlighted = (card: HighlightableCard): boolean => {
        if (!hasHighlight.value) return false;
        if (highlight.mv !== null && !matchesManaValue(card, highlight.mv)) return false;
        if (highlight.category !== null && !matchesCategory(card, highlight.category)) return false;
        if (highlight.colorProduction !== null && !(card.produced_mana?.includes(highlight.colorProduction) ?? false))
            return false;
        return !(highlight.colorConsumption !== null && !consumesColor(card.mana_cost, highlight.colorConsumption));
    };

    const api: DeckHighlightApi = {
        highlight: computed(() => ({
            mv: highlight.mv,
            category: highlight.category,
            colorProduction: highlight.colorProduction,
            colorConsumption: highlight.colorConsumption
        })),
        hasHighlight,
        selectedManaValue,
        selectedCategory,
        selectedColorProduction,
        selectedColorConsumption,
        setManaValue,
        setCategory,
        setColorProduction,
        setColorConsumption,
        clear,
        isHighlighted
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
