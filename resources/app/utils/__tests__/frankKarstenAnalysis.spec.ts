import { describe, expect, it } from "vitest";
import { breakdownCard, karstenArticleUrl, sourcesNeeded } from "../frankKarstenAnalysis.ts";

const NO_PIPS = { W: 0, U: 0, B: 0, R: 0, G: 0 };

describe("breakdownCard", () => {
    it("passes the caller's mana value straight through", () => {
        // Deliberately not re-derived from the cost string — split and MDFC
        // faces would make that ambiguous.
        expect(breakdownCard(["{1}{W}"], 2).cmc).toBe(2);
        expect(breakdownCard([], 7).cmc).toBe(7);
    });

    it("counts repeated pips of one colour", () => {
        const breakdown = breakdownCard(["{1}{W}{W}"], 3);

        expect(breakdown.pips).toEqual({ ...NO_PIPS, W: 2 });
        expect(breakdown.hybridGroups).toEqual([]);
    });

    it("counts each colour of a gold cost separately", () => {
        expect(breakdownCard(["{1}{W}{U}"], 3).pips).toEqual({ ...NO_PIPS, W: 1, U: 1 });
    });

    it("ignores generic, variable, colourless and snow symbols", () => {
        expect(breakdownCard(["{3}"], 3).pips).toEqual(NO_PIPS);
        expect(breakdownCard(["{X}{X}"], 0).pips).toEqual(NO_PIPS);
        expect(breakdownCard(["{2}{C}"], 3).pips).toEqual(NO_PIPS);
        expect(breakdownCard(["{S}{S}"], 2).pips).toEqual(NO_PIPS);
    });

    it("returns an empty breakdown for a card with no cost", () => {
        // Lands, and the back face of an MDFC.
        expect(breakdownCard([null], 0)).toEqual({ cmc: 0, pips: NO_PIPS, hybridGroups: [] });
        expect(breakdownCard([""], 0)).toEqual({ cmc: 0, pips: NO_PIPS, hybridGroups: [] });
    });

    it("sums pips across every face and skips the null ones", () => {
        // Split card: both halves' costs count toward the same requirement.
        expect(breakdownCard(["{W}", null, "{U}"], 1).pips).toEqual({ ...NO_PIPS, W: 1, U: 1 });
    });

    describe("hybrid handling", () => {
        it("records a true hybrid as a group, not as forced pips", () => {
            const breakdown = breakdownCard(["{W/U}"], 1);

            expect(breakdown.pips).toEqual(NO_PIPS);
            expect(breakdown.hybridGroups).toEqual([["W", "U"]]);
        });

        it("normalises each group to WUBRG order", () => {
            expect(breakdownCard(["{U/W}"], 1).hybridGroups).toEqual([["W", "U"]]);
        });

        it("generalises past two colours, though no printed card does", () => {
            // Purely defensive — Magic has never printed a tri-hybrid symbol.
            expect(breakdownCard(["{G/W/U}"], 1).hybridGroups).toEqual([["W", "U", "G"]]);
        });

        it("emits one group per hybrid symbol, so density survives", () => {
            // Yorion is `{3}{W/U}{W/U}` — two pips, each payable either way.
            expect(breakdownCard(["{3}{W/U}{W/U}"], 5).hybridGroups).toEqual([
                ["W", "U"],
                ["W", "U"]
            ]);
        });

        it("treats phyrexian as a forced pip, since life is not a colour", () => {
            const breakdown = breakdownCard(["{W/P}"], 1);

            expect(breakdown.pips).toEqual({ ...NO_PIPS, W: 1 });
            expect(breakdown.hybridGroups).toEqual([]);
        });

        it("treats twobrid as a forced pip, since generic is not a colour", () => {
            const breakdown = breakdownCard(["{2/W}"], 2);

            expect(breakdown.pips).toEqual({ ...NO_PIPS, W: 1 });
            expect(breakdown.hybridGroups).toEqual([]);
        });

        it("keeps forced and hybrid pips of the same colour apart", () => {
            const breakdown = breakdownCard(["{W}{W/U}"], 2);

            expect(breakdown.pips).toEqual({ ...NO_PIPS, W: 1 });
            expect(breakdown.hybridGroups).toEqual([["W", "U"]]);
        });
    });
});

describe("sourcesNeeded", () => {
    describe("table selection", () => {
        it.each(["commander", "duel", "brawl", "paupercommander", "predh", "gladiator"])(
            "uses the 99-card table for %s",
            format => {
                expect(sourcesNeeded(format, 2, 1)).toBe(19);
            }
        );

        it.each([
            "standard",
            "modern",
            "pioneer",
            "legacy",
            "vintage",
            "pauper",
            "penny",
            "premodern",
            "oldschool",
            "future",
            "alchemy",
            "historic",
            "timeless"
        ])("uses the 60-card table for %s", format => {
            expect(sourcesNeeded(format, 2, 1)).toBe(13);
        });

        it("uses the 60-card table for Oathbreaker, which plays 60 despite the commander", () => {
            expect(sourcesNeeded("oathbreaker", 2, 1)).toBe(13);
        });

        it("keeps standardbrawl on the 60-card table while brawl stays on the 99", () => {
            // The near-miss most likely to be mis-filed: the two slugs differ
            // by a prefix, and `CardFormat::rules()` puts Brawl on
            // CommanderProfile (100) but StandardBrawl on its own 60-card one.
            expect(sourcesNeeded("standardbrawl", 2, 1)).toBe(13);
            expect(sourcesNeeded("brawl", 2, 1)).toBe(19);
        });

        it("falls back to the 60-card table for a format slug it has never seen", () => {
            // New Scryfall format slugs must not blow up the mana analysis.
            expect(sourcesNeeded("some-future-format", 2, 1)).toBe(13);
        });
    });

    describe("table lookup", () => {
        it("reads the published values off the 60-card table", () => {
            expect(sourcesNeeded("modern", 1, 1)).toBe(14);
            expect(sourcesNeeded("modern", 2, 2)).toBe(21);
            expect(sourcesNeeded("modern", 4, 4)).toBe(24);
        });

        it("reads the published values off the 99-card table", () => {
            expect(sourcesNeeded("commander", 1, 1)).toBe(19);
            expect(sourcesNeeded("commander", 7, 3)).toBe(26);
            expect(sourcesNeeded("commander", 5, 4)).toBe(36);
        });

        it("returns null for cells Karsten did not publish", () => {
            // Structurally impossible — more pips than mana value.
            expect(sourcesNeeded("modern", 1, 2)).toBeNull();
            expect(sourcesNeeded("modern", 3, 4)).toBeNull();
            // Omitted as trivially satisfied by the time you reach that turn.
            expect(sourcesNeeded("modern", 7, 1)).toBeNull();
            expect(sourcesNeeded("commander", 6, 4)).toBeNull();
        });
    });

    describe("input clamping", () => {
        it("returns null when the card asks for no pips of the colour", () => {
            // Contract, not implementation: the explicit `pipDensity <= 0`
            // guard is belt-and-braces — the table lookup would miss and fall
            // through to null anyway — so this cannot pin the guard itself.
            expect(sourcesNeeded("modern", 3, 0)).toBeNull();
            expect(sourcesNeeded("modern", 3, -1)).toBeNull();
        });

        it("caps pip density at 4", () => {
            expect(sourcesNeeded("modern", 5, 5)).toBe(sourcesNeeded("modern", 5, 4));
            expect(sourcesNeeded("modern", 5, 9)).toBe(22);
        });

        it("clamps mana value into the 1..7 range the tables cover", () => {
            expect(sourcesNeeded("modern", 0, 1)).toBe(sourcesNeeded("modern", 1, 1));
            expect(sourcesNeeded("modern", 15, 2)).toBe(sourcesNeeded("modern", 7, 2));
        });

        it("floors a fractional mana value", () => {
            // Real decks can't produce one, but the value arrives as a plain
            // number from JSON and must not index a missing row.
            expect(sourcesNeeded("modern", 2.9, 1)).toBe(sourcesNeeded("modern", 2, 1));
        });
    });
});

describe("karstenArticleUrl", () => {
    it("links the 2022 update for both deck sizes", () => {
        // Both branches resolve to the same article today; the function is
        // format-keyed so a future 100-card-specific piece can diverge.
        expect(karstenArticleUrl("commander")).toBe(karstenArticleUrl("modern"));
        expect(karstenArticleUrl("modern")).toMatch(/^https:\/\/www\.tcgplayer\.com\/content\/article\//);
    });
});
