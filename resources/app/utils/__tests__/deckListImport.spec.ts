// @vitest-environment node
import { describe, expect, it } from "vitest";
import { makeCommanderResult, makeDeckListCard, makeDeckListLine, makeParseResult } from "@/test/factories/deckList.ts";
import type { ChosenCommandZone, LineChoice } from "../deckListImport.ts";
import {
    blockingLines,
    effectiveLines,
    lineWarnings,
    submissionRows,
    withinIdentity,
    zoneTotals
} from "../deckListImport.ts";

const noCommandZone: ChosenCommandZone = { commander: null, partner: null, signatureSpell: null };
const choicesFor = (...entries: Array<[number, Partial<LineChoice>]>): Record<number, LineChoice> =>
    Object.fromEntries(entries.map(([line, choice]) => [line, { excluded: false, replacement: null, ...choice }]));

describe("effectiveLines", () => {
    it("leaves out excluded and unresolved lines", () => {
        const kept = makeDeckListLine();
        const excluded = makeDeckListLine(makeDeckListCard("Shock"));
        const unresolved = makeDeckListLine(null);

        const result = effectiveLines(
            [kept, excluded, unresolved],
            choicesFor([excluded.line, { excluded: true }]),
            {},
            noCommandZone
        );

        expect(result.map(line => line.line)).toEqual([kept.line]);
    });

    it("imports the replacement picked for an unresolved line, at the line's quantity", () => {
        const unresolved = makeDeckListLine(null, { quantity: 3 });
        const replacement = { card: makeDeckListCard("Chain Lightning"), availability: null };

        const [line] = effectiveLines([unresolved], choicesFor([unresolved.line, { replacement }]), {}, noCommandZone);

        expect([line.card.name, line.quantity]).toEqual(["Chain Lightning", 3]);
    });

    it("applies the chosen zone to a guessed section only", () => {
        const guessed = makeDeckListLine(makeDeckListCard("Pyroblast"), {
            section: 1,
            zone: "side",
            zone_guessed: true
        });
        const stated = makeDeckListLine(makeDeckListCard("Hydroblast"), { section: 1, zone: "side" });

        const result = effectiveLines([guessed, stated], {}, { 1: "main" }, noCommandZone);

        expect(result.map(line => line.zone)).toEqual(["main", "side"]);
    });

    it("takes one copy of a command-zone card out of the main deck, once", () => {
        const krenko = makeDeckListCard("Krenko, Mob Boss");
        const first = makeDeckListLine(krenko, { quantity: 1 });
        const second = makeDeckListLine(krenko, { quantity: 1 });
        const side = makeDeckListLine(makeDeckListCard("Goblin Guide"), { zone: "side" });
        const zone = { ...noCommandZone, commander: makeCommanderResult("Krenko, Mob Boss") };

        const result = effectiveLines([first, second, side], {}, {}, zone);

        expect(result.map(line => [line.quantity, line.deduped])).toEqual([
            [0, 1],
            [1, 0],
            [1, 0]
        ]);
    });

    it("does not dedupe out of the sideboard", () => {
        const side = makeDeckListLine(makeDeckListCard("Krenko, Mob Boss"), { zone: "side" });
        const zone = { ...noCommandZone, commander: makeCommanderResult("Krenko, Mob Boss") };

        expect(effectiveLines([side], {}, {}, zone)[0].deduped).toBe(0);
    });

    it("drops the category of a companion line", () => {
        const companion = makeDeckListLine(makeDeckListCard("Lurrus"), { zone: "companion", category: "Value" });
        const main = makeDeckListLine(makeDeckListCard("Ramp"), { category: "Value" });

        expect(effectiveLines([companion, main], {}, {}, noCommandZone).map(line => line.category)).toEqual([
            null,
            "Value"
        ]);
    });
});

describe("blockingLines", () => {
    it("blocks on unresolved lines until each is replaced or excluded", () => {
        const a = makeDeckListLine(null);
        const b = makeDeckListLine(null);
        const c = makeDeckListLine(null);
        const resolved = makeDeckListLine();
        const choices = choicesFor(
            [a.line, { excluded: true }],
            [b.line, { replacement: { card: makeDeckListCard(), availability: null } }]
        );

        expect(blockingLines([a, b, c, resolved], choices)).toEqual([c]);
    });
});

describe("lineWarnings", () => {
    it("counts copies across lines and the command zone", () => {
        const bolt = makeDeckListCard("Lightning Bolt", { copy_limit: 4 });
        const main = makeDeckListLine(bolt, { quantity: 3 });
        const side = makeDeckListLine(bolt, { quantity: 1, zone: "side" });
        const ring = makeDeckListLine(makeDeckListCard("Sol Ring", { copy_limit: 1 }));
        const effective = effectiveLines([main, side, ring], {}, {}, noCommandZone);

        // 3 + 1 Bolts is exactly the limit; one more makes it too many.
        expect(lineWarnings(effective, noCommandZone, false)[main.line]).toEqual([]);
        const more = effectiveLines(
            [main, makeDeckListLine(bolt, { quantity: 2, zone: "side" })],
            {},
            {},
            noCommandZone
        );
        expect(lineWarnings(more, noCommandZone, false)[main.line]).toEqual(["too_many_copies"]);

        // A singleton card that is also the commander is one copy too many.
        const commanderRing = {
            ...noCommandZone,
            commander: makeCommanderResult("Sol Ring", { color_identity: null })
        };
        const twoRings = effectiveLines([makeDeckListLine(ring.card, { quantity: 2 })], {}, {}, commanderRing);
        expect(Object.values(lineWarnings(twoRings, commanderRing, false))[0]).toEqual(["too_many_copies"]);
    });

    it("never limits a card without a copy limit", () => {
        const mountain = makeDeckListLine(makeDeckListCard("Mountain", { copy_limit: null }), { quantity: 40 });

        expect(
            lineWarnings(effectiveLines([mountain], {}, {}, noCommandZone), noCommandZone, false)[mountain.line]
        ).toEqual([]);
    });

    it("flags colours outside the command zone only where identity is enforced and a commander is chosen", () => {
        const blue = makeDeckListLine(makeDeckListCard("Counterspell", { color_identity: "U" }));
        const red = makeDeckListLine(makeDeckListCard("Shock", { color_identity: "R" }));
        const colourless = makeDeckListLine(makeDeckListCard("Sol Ring", { color_identity: null }));
        const lines = [blue, red, colourless];
        const zone = { ...noCommandZone, commander: makeCommanderResult("Krenko, Mob Boss", { color_identity: "R" }) };
        const effective = effectiveLines(lines, {}, {}, zone);

        expect(lines.map(line => lineWarnings(effective, zone, true)[line.line])).toEqual([["color_identity"], [], []]);
        expect(lineWarnings(effective, zone, false)[blue.line]).toEqual([]);
        expect(lineWarnings(effective, noCommandZone, true)[blue.line]).toEqual([]);
    });

    it("widens the identity with the partner", () => {
        const blue = makeDeckListLine(makeDeckListCard("Counterspell", { color_identity: "U" }));
        const zone = {
            ...noCommandZone,
            commander: makeCommanderResult("Akiri", { color_identity: "RW" }),
            partner: makeCommanderResult("Thrasios", { color_identity: "GU" })
        };

        expect(lineWarnings(effectiveLines([blue], {}, {}, zone), zone, true)[blue.line]).toEqual([]);
    });

    it("flags a card that is not legal", () => {
        const banned = makeDeckListLine(makeDeckListCard("Mana Drain", { is_legal: false }));

        expect(
            lineWarnings(effectiveLines([banned], {}, {}, noCommandZone), noCommandZone, false)[banned.line]
        ).toEqual(["not_legal"]);
    });
});

describe("withinIdentity", () => {
    it.each([
        ["R", "R", true],
        ["UR", "R", false],
        [null, "", true],
        ["G", "BG", true]
    ])("%s within %s → %s", (card, identity, expected) => {
        expect(withinIdentity(card, identity)).toBe(expected);
    });
});

describe("zoneTotals and submissionRows", () => {
    it("counts the command zone into the main deck and leaves out emptied lines", () => {
        const krenko = makeDeckListLine(makeDeckListCard("Krenko, Mob Boss"));
        const mountains = makeDeckListLine(makeDeckListCard("Mountain"), { quantity: 30, category: "Lands" });
        const side = makeDeckListLine(makeDeckListCard("Pyroblast"), { zone: "side", quantity: 2 });
        const zone = { ...noCommandZone, commander: makeCommanderResult("Krenko, Mob Boss") };
        const effective = effectiveLines(makeParseResult([krenko, mountains, side]).lines, {}, {}, zone);

        expect(zoneTotals(effective, zone)).toEqual({ main: 31, side: 2, companion: 0 });
        expect(submissionRows(effective)).toEqual([
            {
                oracle_card_id: "oracle-Mountain",
                default_card_id: "printing-Mountain",
                quantity: 30,
                zone: "main",
                category: "Lands"
            },
            {
                oracle_card_id: "oracle-Pyroblast",
                default_card_id: "printing-Pyroblast",
                quantity: 2,
                zone: "side",
                category: null
            }
        ]);
    });
});
