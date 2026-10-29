// @vitest-environment node

import { describe, expect, it } from "vitest";
import type { DefaultCardImage } from "Types/defaultCardImage.ts";
import { searchSets, setsOfPrintings } from "../printingSets.ts";

const SETS = {
    m21: { code: "m21", name: "Core Set 2021", path: "/set/m21.svg" },
    pm21: { code: "pm21", name: "Core Set 2021 Promos", path: null },
    znr: { code: "znr", name: "Zendikar Rising", path: "/set/znr.svg" },
    lea: { code: "lea", name: "Limited Edition Alpha", path: "/set/lea.svg" }
};

const printing = (id: string, set: DefaultCardImage["set"]): DefaultCardImage => ({
    id,
    name: "Forest",
    card_image_0: null,
    card_image_1: null,
    artist: null,
    cn: id,
    finishes: ["nonfoil"],
    set
});

describe("setsOfPrintings", () => {
    it("lists each set once with how many printings it holds, in first-printing order", () => {
        const sets = setsOfPrintings([
            printing("1", SETS.znr),
            printing("2", SETS.m21),
            printing("3", SETS.znr),
            printing("4", SETS.lea),
            printing("5", SETS.znr)
        ]);

        expect(sets).toEqual([
            { ...SETS.znr, count: 3 },
            { ...SETS.m21, count: 1 },
            { ...SETS.lea, count: 1 }
        ]);
    });

    it("is empty for no printings", () => {
        expect(setsOfPrintings([])).toEqual([]);
    });
});

describe("searchSets", () => {
    const sets = [
        { ...SETS.znr, count: 1 },
        { ...SETS.pm21, count: 2 },
        { ...SETS.m21, count: 20 },
        { ...SETS.lea, count: 3 }
    ];

    it.each([
        ["the code, ignoring case", "ZNR", ["znr"]],
        ["part of the code", "m2", ["pm21", "m21"]],
        ["part of the name, ignoring case", "zendikar RISING", ["znr"]]
    ])("matches on %s", (_, query, expected) => {
        expect(searchSets(sets, query).map(set => set.code)).toEqual(expected);
    });

    it("moves the set whose code is the query to the top", () => {
        expect(searchSets(sets, "M21").map(set => set.code)).toEqual(["m21", "pm21"]);
    });

    it("keeps the given order when no code is the query", () => {
        expect(searchSets(sets, "e").map(set => set.code)).toEqual(["znr", "pm21", "m21", "lea"]);
    });

    it("leaves an exact match that already leads in place", () => {
        expect(searchSets(sets, "znr").map(set => set.code)).toEqual(["znr"]);
    });

    it("is empty when nothing matches", () => {
        expect(searchSets(sets, "xyz")).toEqual([]);
    });
});
