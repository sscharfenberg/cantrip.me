import { describe, expect, it } from "vitest";
import { combineCI, isSubsetCI } from "../colorIdentity.ts";

describe("isSubsetCI", () => {
    it("accepts a card whose identity is fully contained in the commander's", () => {
        expect(isSubsetCI("W", "WU")).toBe(true);
        expect(isSubsetCI("WU", "WUBRG")).toBe(true);
        expect(isSubsetCI("WU", "WU")).toBe(true);
    });

    it("rejects a card carrying a colour the commander does not", () => {
        expect(isSubsetCI("WU", "W")).toBe(false);
        expect(isSubsetCI("G", "WUBR")).toBe(false);
    });

    it("treats a colourless card as legal in any deck", () => {
        // `oracle_cards.color_identity` is NULL for colourless cards. The
        // empty string is covered too because the guard is a truthiness check,
        // and a caller assembling an identity by hand can produce one.
        expect(isSubsetCI(null, "WU")).toBe(true);
        expect(isSubsetCI(null, "")).toBe(true);
        expect(isSubsetCI("", "WU")).toBe(true);
    });

    it("rejects any coloured card in a colourless commander deck", () => {
        expect(isSubsetCI("W", "")).toBe(false);
    });

    it("ignores the order the letters arrive in", () => {
        // Scryfall stores identities alphabetically (`UW`, `BU`, `GR`), not in
        // WUBRG order, so neither side can be compared as a whole string.
        expect(isSubsetCI("GW", "WUBRG")).toBe(true);
        expect(isSubsetCI("W", "GRBUW")).toBe(true);
        expect(isSubsetCI("UW", "WU")).toBe(true);
    });
});

describe("combineCI", () => {
    it("returns the empty identity for an empty or all-null input", () => {
        expect(combineCI([])).toBe("");
        expect(combineCI([null, null])).toBe("");
        expect(combineCI([""])).toBe("");
    });

    it("normalises to WUBRG order regardless of input order", () => {
        // The inputs come out of the DB alphabetically; the UI wants WUBRG.
        expect(combineCI(["G", "W"])).toBe("WG");
        expect(combineCI(["GRBUW"])).toBe("WUBRG");
        expect(combineCI(["UW"])).toBe("WU");
    });

    it("unions the parts and de-duplicates shared colours", () => {
        // The partner case: two commanders, overlapping identities.
        expect(combineCI(["WU", "UB"])).toBe("WUB");
        expect(combineCI(["W", "W"])).toBe("W");
    });

    it("skips null parts, so a deck with only one commander still resolves", () => {
        expect(combineCI(["WU", null, "B"])).toBe("WUB");
    });

    it("drops anything outside WUBRG, so colourless never widens an identity", () => {
        expect(combineCI(["C"])).toBe("");
        expect(combineCI(["WC"])).toBe("W");
    });
});
