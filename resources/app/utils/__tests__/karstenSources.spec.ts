// @vitest-environment node

import { describe, expect, it } from "vitest";
import type { SourceCard, SourceRole } from "../karstenSources.ts";
import { sourceRoles, sourceValue } from "../karstenSources.ts";

/** Card with only the fields the classifier reads; everything else defaults to "not a source". */
const card = (overrides: Partial<SourceCard>): SourceCard => ({
    type_line: "Creature — Human",
    cmc: 2,
    produced_mana: null,
    mana_source: null,
    ...overrides
});

/** A resolver that answers every pattern with the same colours, standing in for a deck's lands. */
const resolvesTo = (colors: string[]) => (): string[] => colors;

describe("sourceRoles — lands", () => {
    it("counts a land as a full source of its colours for every spell", () => {
        expect(
            sourceRoles(card({ type_line: "Land — Swamp Forest", cmc: 0, produced_mana: ["B", "G"] }), null)
        ).toEqual([{ colors: ["B", "G"], weight: 1, minMv: 0 }]);
    });

    it("counts a fetchland as a full source of whatever it can find", () => {
        const fetch = card({ type_line: "Land", cmc: 0, fetch_pattern: "typed:BG" });

        expect(sourceRoles(fetch, resolvesTo(["B", "G", "U"]))).toEqual([
            { colors: ["B", "G", "U"], weight: 1, minMv: 0 }
        ]);
    });

    it("counts a fetchland with nothing to find as no source", () => {
        const fetch = card({ type_line: "Land", cmc: 0, fetch_pattern: "typed:BG" });

        expect(sourceRoles(fetch, resolvesTo([]))).toEqual([]);
    });

    it("counts a spell/land MDFC as a land, not by its sorcery front", () => {
        // Bala Ged Recovery: a ritual-shaped front would otherwise count for nothing.
        const mdfc = card({ type_line: "Sorcery", cmc: 3, produced_mana: ["G"], is_mdfc_land: true });

        expect(sourceRoles(mdfc, null)).toEqual([{ colors: ["G"], weight: 1, minMv: 0 }]);
    });
});

describe("sourceRoles — non-land producers", () => {
    it("counts a mana creature as half a source from the turn after it lands", () => {
        const elves = card({ type_line: "Creature — Elf Druid", cmc: 1, produced_mana: ["G"], mana_source: "ability" });

        expect(sourceRoles(elves, null)).toEqual([{ colors: ["G"], weight: 1 / 2, minMv: 2 }]);
    });

    it("counts a mana rock as three-fourths of a source from the turn after it lands", () => {
        const signet = card({ type_line: "Artifact", cmc: 2, produced_mana: ["W", "U"], mana_source: "ability" });

        expect(sourceRoles(signet, null)).toEqual([{ colors: ["W", "U"], weight: 3 / 4, minMv: 3 }]);
    });

    it("does not count a ritual, which fixes no later turn", () => {
        const darkRitual = card({ type_line: "Instant", cmc: 1, produced_mana: ["B"], mana_source: "ability" });
        const seethingSong = card({ type_line: "Sorcery", cmc: 3, produced_mana: ["R"], mana_source: "ability" });

        expect(sourceRoles(darkRitual, null)).toEqual([]);
        expect(sourceRoles(seethingSong, null)).toEqual([]);
    });

    it("counts a Treasure maker as a quarter of a source, whatever its type", () => {
        // Smothering Tithe is an enchantment and Big Score an instant; either
        // way the card's own mana is one Treasure, not a rock or a ritual.
        const tithe = card({ type_line: "Enchantment", cmc: 4, produced_mana: ["W", "U"], mana_source: "token" });
        const bigScore = card({ type_line: "Instant", cmc: 4, produced_mana: ["R"], mana_source: "token" });

        expect(sourceRoles(tithe, null)).toEqual([{ colors: ["W", "U"], weight: 1 / 4, minMv: 5 }]);
        expect(sourceRoles(bigScore, null)).toEqual([{ colors: ["R"], weight: 1 / 4, minMv: 5 }]);
    });

    it("does not count mana a card only makes after transforming", () => {
        // Search for Azcanta: Scryfall lists the land back's {U} as produced mana.
        const azcanta = card({ type_line: "Legendary Enchantment", cmc: 2, produced_mana: ["U"], mana_source: null });

        expect(sourceRoles(azcanta, null)).toEqual([]);
    });

    it("ignores a non-land that produces nothing", () => {
        expect(sourceRoles(card({ produced_mana: [] }), null)).toEqual([]);
        expect(sourceRoles(card({}), null)).toEqual([]);
    });
});

describe("sourceRoles — land-search spells", () => {
    it("counts two-mana ramp as three-fourths of a source of everything it can find, from MV 3", () => {
        const threeVisits = card({ type_line: "Sorcery", cmc: 2, land_search_pattern: "typed:G" });

        expect(sourceRoles(threeVisits, resolvesTo(["B", "G"]))).toEqual([
            { colors: ["B", "G"], weight: 3 / 4, minMv: 3 }
        ]);
    });

    it("counts three-mana ramp as a quarter of a source at MV 4 and half from MV 5", () => {
        const cultivate = card({ type_line: "Sorcery", cmc: 3, land_search_pattern: "basic" });

        expect(sourceRoles(cultivate, resolvesTo(["G", "W"]))).toEqual([
            { colors: ["G", "W"], weight: 1 / 4, minMv: 4 },
            { colors: ["G", "W"], weight: 1 / 2, minMv: 5 }
        ]);
    });

    it("counts a land-search spell with nothing to find as no source", () => {
        const threeVisits = card({ type_line: "Sorcery", cmc: 2, land_search_pattern: "typed:G" });

        expect(sourceRoles(threeVisits, resolvesTo([]))).toEqual([]);
    });

    it("cannot resolve a pattern without a resolver", () => {
        expect(sourceRoles(card({ land_search_pattern: "basic" }), null)).toEqual([]);
    });

    it("keeps both roles of a creature that makes mana and fetches a land", () => {
        const both = card({
            type_line: "Creature — Elf",
            cmc: 2,
            produced_mana: ["G"],
            mana_source: "ability",
            land_search_pattern: "basic"
        });

        expect(sourceRoles(both, resolvesTo(["W"]))).toEqual([
            { colors: ["G"], weight: 1 / 2, minMv: 3 },
            { colors: ["W"], weight: 3 / 4, minMv: 3 }
        ]);
    });
});

describe("sourceValue", () => {
    const roles: SourceRole[] = [
        { colors: ["G"], weight: 1 / 2, minMv: 2 },
        { colors: ["G", "B"], weight: 3 / 4, minMv: 3 }
    ];

    it("is nothing toward a spell cheaper than every role's gate", () => {
        expect(sourceValue(roles, ["G"], 1)).toBe(0);
    });

    it("takes only the roles online by the spell's mana value", () => {
        expect(sourceValue(roles, ["G"], 2)).toBe(1 / 2);
    });

    it("takes the best role, never the sum — it is still one card", () => {
        expect(sourceValue(roles, ["G"], 3)).toBe(3 / 4);
    });

    it("matches a role on any colour of a combo", () => {
        expect(sourceValue(roles, ["W", "B"], 3)).toBe(3 / 4);
    });

    it("is nothing for colours no role covers", () => {
        expect(sourceValue(roles, ["U"], 7)).toBe(0);
    });
});
