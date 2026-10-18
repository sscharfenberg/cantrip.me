// @vitest-environment node

import { describe, expect, it } from "vitest";
import {
    type DeckFilters,
    type FilterableDeck,
    deckFilterOptions,
    emptyDeckFilters,
    filterDecks,
    hasDeckFilters,
    isDeckFilterUseful,
    toggleDeckFilter
} from "../deckListFilters.ts";

const deck = (name: string, state: string, bracket: number | null, visibility: string) => ({
    name,
    state,
    bracket,
    visibility
});

/** Four decks that every filter splits differently. */
const decks = [
    deck("Atraxa", "built", 3, "public"),
    deck("Krenko", "planned", 2, "private"),
    deck("Edgar", "built", null, "private"),
    deck("Yuriko", "planned", 3, "private")
];

const names = (list: Array<FilterableDeck & { name: string }>): string[] => list.map(d => d.name);

const filters = (patch: Partial<DeckFilters>): DeckFilters => ({ ...emptyDeckFilters(), ...patch });

describe("filterDecks", () => {
    it("shows every deck while nothing is selected", () => {
        expect(names(filterDecks(decks, emptyDeckFilters()))).toEqual(["Atraxa", "Krenko", "Edgar", "Yuriko"]);
    });

    it("filters each group by its own field", () => {
        expect(names(filterDecks(decks, filters({ state: "planned" })))).toEqual(["Krenko", "Yuriko"]);
        expect(names(filterDecks(decks, filters({ bracket: "3" })))).toEqual(["Atraxa", "Yuriko"]);
        expect(names(filterDecks(decks, filters({ visibility: "public" })))).toEqual(["Atraxa"]);
    });

    it("matches a deck without a bracket as `none`", () => {
        expect(names(filterDecks(decks, filters({ bracket: "none" })))).toEqual(["Edgar"]);
    });

    it("combines groups", () => {
        expect(names(filterDecks(decks, filters({ state: "built", visibility: "private" })))).toEqual(["Edgar"]);
        expect(filterDecks(decks, filters({ state: "planned", visibility: "public" }))).toEqual([]);
    });
});

describe("deckFilterOptions", () => {
    it("offers the values the decks hold, in display order", () => {
        expect(deckFilterOptions(decks, "state", null)).toEqual(["planned", "built"]);
        expect(deckFilterOptions(decks, "bracket", null)).toEqual(["2", "3", "none"]);
        expect(deckFilterOptions(decks, "visibility", null)).toEqual(["public", "private"]);
    });

    it("keeps offering an active value no deck holds any more, so it can be switched off", () => {
        const allBuilt = [deck("A", "built", 1, "private")];

        expect(deckFilterOptions(allBuilt, "state", "planned")).toEqual(["planned", "built"]);
    });
});

describe("isDeckFilterUseful", () => {
    it("hides a group with nothing to choose between", () => {
        expect(isDeckFilterUseful(["private"], null)).toBe(false);
        expect(isDeckFilterUseful(["public", "private"], null)).toBe(true);
    });

    it("keeps a group with an active value, whatever it offers", () => {
        expect(isDeckFilterUseful(["private"], "private")).toBe(true);
    });
});

describe("toggleDeckFilter", () => {
    it("selects a value, replacing the group's previous one", () => {
        expect(toggleDeckFilter(emptyDeckFilters(), "bracket", "2").bracket).toBe("2");
        expect(toggleDeckFilter(filters({ bracket: "2" }), "bracket", "3").bracket).toBe("3");
    });

    it("deselects the active value", () => {
        expect(toggleDeckFilter(filters({ bracket: "2" }), "bracket", "2").bracket).toBeNull();
    });

    it("leaves the other groups alone", () => {
        expect(toggleDeckFilter(filters({ state: "built" }), "visibility", "public")).toEqual(
            filters({ state: "built", visibility: "public" })
        );
    });
});

describe("hasDeckFilters", () => {
    it("is true once any group filters", () => {
        expect(hasDeckFilters(emptyDeckFilters())).toBe(false);
        expect(hasDeckFilters(filters({ visibility: "private" }))).toBe(true);
    });
});
