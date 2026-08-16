import { describe, expect, it } from "vitest";
import { makeDeckCard } from "@/test/factories/deckCard.ts";
import { GROUP_ORDER, compareCards, resolveGroup } from "../deckGrouping.ts";

describe("GROUP_ORDER", () => {
    it("is the full precedence chain, catch-all last", () => {
        // Pinned exactly: `resolveGroup` scans in this order, so every
        // dual-type precedence rule below is a consequence of this array.
        expect([...GROUP_ORDER]).toEqual([
            "land",
            "creature",
            "planeswalker",
            "battle",
            "instant",
            "sorcery",
            "artifact",
            "enchantment",
            "other"
        ]);
    });
});

describe("resolveGroup", () => {
    it("buckets each single-type card into its own group", () => {
        expect(resolveGroup("Creature — Cat")).toBe("creature");
        expect(resolveGroup("Legendary Planeswalker — Teferi")).toBe("planeswalker");
        expect(resolveGroup("Battle — Siege")).toBe("battle");
        expect(resolveGroup("Instant")).toBe("instant");
        expect(resolveGroup("Sorcery")).toBe("sorcery");
        expect(resolveGroup("Artifact — Equipment")).toBe("artifact");
        expect(resolveGroup("Enchantment — Aura")).toBe("enchantment");
        expect(resolveGroup("Basic Land — Island")).toBe("land");
    });

    it("prefers land over every other type it shares a type line with", () => {
        // Matches what Moxfield and Archidekt do: a card you play from the land
        // drop belongs in the land section, whatever else it is.
        expect(resolveGroup("Land Creature — Forest Dryad")).toBe("land"); // Dryad Arbor
        expect(resolveGroup("Artifact Land")).toBe("land"); // Seat of the Synod
        expect(resolveGroup("Enchantment Land — Urza's Saga")).toBe("land");
        expect(resolveGroup("Land — Urza's Mine")).toBe("land");
    });

    it("prefers creature over artifact and enchantment", () => {
        expect(resolveGroup("Artifact Creature — Golem")).toBe("creature");
        expect(resolveGroup("Enchantment Creature — God")).toBe("creature");
    });

    it("prefers artifact over enchantment", () => {
        // The theros gods' weapons — Bow of Nylea, Spear of Heliod — are all
        // "Legendary Enchantment Artifact" and belong in the artifact section.
        expect(resolveGroup("Legendary Enchantment Artifact")).toBe("artifact");
    });

    it("falls back to `other` for type lines it does not recognise", () => {
        expect(resolveGroup("")).toBe("other");
        expect(resolveGroup("Scheme")).toBe("other");
        expect(resolveGroup("Conspiracy")).toBe("other");
    });

    it("matches case-sensitively, as Scryfall type lines are title-cased", () => {
        expect(resolveGroup("creature — cat")).toBe("other");
    });
});

describe("compareCards", () => {
    const names = (cards: { name: string }[]): string[] => cards.map(card => card.name);

    /**
     * Alphabetical order and mana order disagree on this set, which is what
     * makes it useful: each mode has to pick its own answer, and neither can
     * pass by accident.
     */
    const disagreeing = () => [
        makeDeckCard({ name: "Ancestral Recall", cmc: 1 }),
        makeDeckCard({ name: "Zurgo Bellstriker", cmc: 1 }),
        makeDeckCard({ name: "Black Lotus", cmc: 0 })
    ];

    describe("in name mode", () => {
        it("sorts alphabetically and ignores mana value entirely", () => {
            expect(names(disagreeing().sort(compareCards("name")))).toEqual([
                "Ancestral Recall",
                "Black Lotus",
                "Zurgo Bellstriker"
            ]);
        });
    });

    describe("in mana mode", () => {
        it("sorts by mana value first, ahead of the name", () => {
            // Black Lotus sorts last by name and first by cost.
            expect(names(disagreeing().sort(compareCards("mana")))).toEqual([
                "Black Lotus",
                "Ancestral Recall",
                "Zurgo Bellstriker"
            ]);
        });

        it("orders large mana values numerically, not as strings", () => {
            const cards = [
                makeDeckCard({ name: "Emrakul, the Aeons Torn", cmc: 15 }),
                makeDeckCard({ name: "Ulamog, the Infinite Gyre", cmc: 11 }),
                makeDeckCard({ name: "Void Winnower", cmc: 9 })
            ];

            expect(names(cards.sort(compareCards("mana")))).toEqual([
                "Void Winnower",
                "Ulamog, the Infinite Gyre",
                "Emrakul, the Aeons Torn"
            ]);
        });

        it("breaks ties alphabetically so equal-cost cards keep a stable order", () => {
            const cards = [
                makeDeckCard({ name: "Swords to Plowshares", cmc: 1 }),
                makeDeckCard({ name: "Path to Exile", cmc: 1 }),
                makeDeckCard({ name: "Giant Growth", cmc: 1 })
            ];

            expect(names(cards.sort(compareCards("mana")))).toEqual([
                "Giant Growth",
                "Path to Exile",
                "Swords to Plowshares"
            ]);
        });
    });

    it("returns 0 for two cards that tie on every key it looks at", () => {
        const card = makeDeckCard({ name: "Forest", cmc: 0 });
        const duplicate = makeDeckCard({ name: "Forest", cmc: 0 });

        expect(compareCards("mana")(card, duplicate)).toBe(0);
        expect(compareCards("name")(card, duplicate)).toBe(0);
    });
});
