/**
 * Choices for the card-stack filter dropdowns, shipped by
 * `CardStackFilterService::options` next to the collection and container
 * tables. Read from the unfiltered rows, so a choice never disappears because
 * another filter is active.
 */
export interface CardStackFilterOptions {
    /** Language codes the rows hold. */
    languages: string[];
    /** Every `CardCondition` value. */
    conditions: string[];
    /** Every finish label (`nonfoil`, `foil`, `etched`). */
    finishes: string[];
    /** Rarities the rows hold, in display order. */
    rarities: string[];
    /** Card types matched against the type line (`Creature`, `Land`, …). */
    types: string[];
    /** Sets the rows hold, ordered by name. */
    sets: Array<{ code: string; name: string; path: string | null }>;
    /** The user's containers — collection page only. */
    containers?: Array<{ id: string; name: string }>;
    /** Decks explicitly claiming at least one row — owner only. */
    decks?: Array<{ id: string; name: string }>;
}

/**
 * A change to the table's filters, keyed by query parameter. `null` removes
 * the parameter. Handed to the DataTable's `setFilters`.
 */
export type CardStackFilterPatch = Record<string, string | null>;
