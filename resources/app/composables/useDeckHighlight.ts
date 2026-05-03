import { computed, inject, provide, ref, type ComputedRef, type InjectionKey } from "vue";
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
    highlight: ComputedRef<DeckHighlight | null>;
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

/**
 * Called once at the page root (DeckPage). Returns the api so the page
 * can also read it directly (e.g. for the page-level "clear selection"
 * button) without going through a second `inject` call.
 */
export const provideDeckHighlight = (): DeckHighlightApi => {
    const highlight = ref<DeckHighlight | null>(null);

    const hasHighlight = computed(() => highlight.value !== null);

    const selectedManaValue = computed<number | null>(() =>
        highlight.value?.axis === "mv" ? highlight.value.value : null
    );
    const selectedCategory = computed<DeckStatsSelection | null>(() =>
        highlight.value?.axis === "category" ? highlight.value.selection : null
    );
    const selectedColorProduction = computed<HighlightColor | null>(() =>
        highlight.value?.axis === "color-production" ? highlight.value.color : null
    );
    const selectedColorConsumption = computed<HighlightColor | null>(() =>
        highlight.value?.axis === "color-consumption" ? highlight.value.color : null
    );

    const setManaValue = (cmc: number | null): void => {
        highlight.value = cmc === null ? null : { axis: "mv", value: cmc };
    };
    const setCategory = (selection: DeckStatsSelection | null): void => {
        highlight.value = selection === null ? null : { axis: "category", selection };
    };
    const setColorProduction = (color: HighlightColor | null): void => {
        highlight.value = color === null ? null : { axis: "color-production", color };
    };
    const setColorConsumption = (color: HighlightColor | null): void => {
        highlight.value = color === null ? null : { axis: "color-consumption", color };
    };
    const clear = (): void => {
        highlight.value = null;
    };

    const isHighlighted = (card: HighlightableCard): boolean => {
        const h = highlight.value;
        if (h === null) return false;
        if (h.axis === "mv") {
            const floored = Math.floor(card.cmc);
            return h.value === 8 ? floored >= 8 : floored === h.value;
        }
        if (h.axis === "category") {
            const sel = h.selection;
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
        }
        if (h.axis === "color-production") {
            return card.produced_mana?.includes(h.color) ?? false;
        }
        // color-consumption
        return consumesColor(card.mana_cost, h.color);
    };

    const api: DeckHighlightApi = {
        highlight: computed(() => highlight.value),
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
