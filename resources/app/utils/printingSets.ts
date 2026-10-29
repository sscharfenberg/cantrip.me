import type { DefaultCardImage } from "Types/defaultCardImage.ts";

/** One set a card was printed in, as the switch-printing set search lists it. */
export type PrintingSet = DefaultCardImage["set"] & {
    /** How many of the given printings belong to this set. */
    count: number;
};

/**
 * The distinct sets of a list of printings, each with its printing count.
 *
 * Sets keep the order of their first printing, so printings that arrive
 * newest set first (as every deck printings endpoint sends them) give sets
 * newest first.
 *
 * @param printings - Printings to group, in the order the sets should take.
 * @return One entry per set code.
 */
export function setsOfPrintings(printings: DefaultCardImage[]): PrintingSet[] {
    const sets = new Map<string, PrintingSet>();
    for (const { set } of printings) {
        const known = sets.get(set.code);
        if (known) known.count++;
        else sets.set(set.code, { ...set, count: 1 });
    }
    return [...sets.values()];
}

/**
 * The sets whose code or name contains the query, ignoring case: a set whose
 * code *is* the query comes first, the rest keep their given order.
 *
 * @param sets - Sets to search, in the order matches should otherwise take.
 * @param query - Search text; callers trim it.
 * @return The matching sets, best match first.
 */
export function searchSets(sets: PrintingSet[], query: string): PrintingSet[] {
    const needle = query.toLowerCase();
    const matches = sets.filter(
        set => set.code.toLowerCase().includes(needle) || set.name.toLowerCase().includes(needle)
    );
    const exact = matches.findIndex(set => set.code.toLowerCase() === needle);
    if (exact > 0) matches.unshift(...matches.splice(exact, 1));
    return matches;
}
