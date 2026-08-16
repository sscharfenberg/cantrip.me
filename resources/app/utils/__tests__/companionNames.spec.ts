import { describe, expect, it } from "vitest";
import { COMPANION_NAMES, isCompanionCard } from "../companionNames.ts";

describe("COMPANION_NAMES", () => {
    it("holds exactly the ten Ikoria companions", () => {
        expect(COMPANION_NAMES).toHaveLength(10);
        expect(new Set(COMPANION_NAMES).size).toBe(10);
    });

    it("pins the exact list, so any edit has to be deliberate", () => {
        // This mirrors `App\Companions\CompanionRegistry::NAMES` by hand and
        // cannot detect drift on its own — nothing here reads the PHP. What it
        // does buy: a name changed or dropped on a whim fails loudly, and the
        // failure message names the file to check on the backend side.
        expect([...COMPANION_NAMES]).toEqual([
            "Gyruda, Doom of Depths",
            "Jegantha, the Wellspring",
            "Kaheera, the Orphanguard",
            "Keruga, the Macrosage",
            "Lurrus of the Dream-Den",
            "Lutri, the Spellchaser",
            "Obosh, the Preypiercer",
            "Umori, the Collector",
            "Yorion, Sky Nomad",
            "Zirda, the Dawnwaker"
        ]);
    });
});

describe("isCompanionCard", () => {
    it("recognises every name in the registry", () => {
        for (const name of COMPANION_NAMES) {
            expect(isCompanionCard(name)).toBe(true);
        }
    });

    it("rejects a card that is not a companion", () => {
        expect(isCompanionCard("Lightning Bolt")).toBe(false);
        expect(isCompanionCard("")).toBe(false);
    });

    it("matches on the exact oracle name only", () => {
        // Oracle names arrive from Scryfall verbatim, so the comparison is
        // intentionally strict — no case folding, no partial match.
        expect(isCompanionCard("lurrus of the dream-den")).toBe(false);
        expect(isCompanionCard("Lurrus")).toBe(false);
        expect(isCompanionCard("Lurrus of the Dream-Den ")).toBe(false);
    });
});
