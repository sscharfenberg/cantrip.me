/**
 * Badge filters of a deck-list format folder: deck state, Commander Bracket
 * and visibility. One value per group at most; `null` means the group does
 * not filter.
 */
export type DeckFilterKey = "state" | "bracket" | "visibility";

export type DeckFilters = Record<DeckFilterKey, string | null>;

/** The fields a filter reads off a deck row. */
export interface FilterableDeck {
    state: string;
    bracket: number | null;
    visibility: string;
}

export const DECK_FILTER_KEYS: readonly DeckFilterKey[] = ["state", "bracket", "visibility"];

/** Filter value for a deck without a bracket. */
export const NO_BRACKET = "none";

/** Display order of each group's badges. */
const ORDER: Record<DeckFilterKey, readonly string[]> = {
    state: ["planned", "built", "archived"],
    bracket: ["1", "2", "3", "4", "5", NO_BRACKET],
    visibility: ["public", "private"]
};

export const emptyDeckFilters = (): DeckFilters => ({ state: null, bracket: null, visibility: null });

/** A deck's value in one filter group — its bracket as a string, `none` when unset. */
export const deckFilterValue = (deck: FilterableDeck, key: DeckFilterKey): string => {
    if (key !== "bracket") return deck[key];
    return deck.bracket === null ? NO_BRACKET : String(deck.bracket);
};

/** The decks matching every active filter. */
export const filterDecks = <T extends FilterableDeck>(decks: T[], filters: DeckFilters): T[] =>
    decks.filter(deck => DECK_FILTER_KEYS.every(key => filters[key] === null || deckFilterValue(deck, key) === filters[key]));

/**
 * The badges one group offers: every value the folder's decks hold, in
 * display order — plus the active value even when no deck holds it any
 * more (a deck changed state from its row menu), so the badge that hides
 * every deck can still be clicked off.
 */
export const deckFilterOptions = (decks: FilterableDeck[], key: DeckFilterKey, active: string | null): string[] => {
    const present = new Set(decks.map(deck => deckFilterValue(deck, key)));
    if (active !== null) present.add(active);
    return ORDER[key].filter(value => present.has(value));
};

/**
 * Whether a group is worth showing: it needs two values to choose between,
 * or an active value to switch off. A folder of private decks has nothing
 * to filter by visibility.
 */
export const isDeckFilterUseful = (options: string[], active: string | null): boolean =>
    options.length >= 2 || active !== null;

/** Click on a badge: activate its value, or deactivate it when it is the active one. */
export const toggleDeckFilter = (filters: DeckFilters, key: DeckFilterKey, value: string): DeckFilters => ({
    ...filters,
    [key]: filters[key] === value ? null : value
});

/** Whether any group filters. */
export const hasDeckFilters = (filters: DeckFilters): boolean => DECK_FILTER_KEYS.some(key => filters[key] !== null);
