// @vitest-environment node

import { describe, expect, it } from "vitest";
import { ref } from "vue";
import { makeCategory, makeCommander, makeCompanion, makeDeckCard } from "@/test/factories/deckCard.ts";
import type { AutomaticSpell, KarstenRowPick } from "@/utils/frankKarstenAnalysis";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion } from "Types/deckPage.ts";
import { useDeckStats } from "../useDeckStats.ts";

/** Zero pips in every colour — the baseline every tally assertion starts from. */
const NO_PIPS = { W: 0, U: 0, B: 0, R: 0, G: 0 };

/**
 * A Karsten row without its pick fields (`key`, `picked`, `automatic`,
 * `candidates`), for the tests about what a row is priced at. The pick
 * fields have their own describe block.
 */
const priced = <T extends KarstenRowPick>(rows: T[]): Partial<T>[] =>
    rows.map(row => {
        const pricedRow: Partial<T> = { ...row };
        for (const field of ["key", "picked", "automatic", "candidates"] as const) delete pricedRow[field];
        return pricedRow;
    });

/** Call the composable with everything defaulted; pass only what a test needs. */
const setup = (
    options: {
        cards?: DeckCardRow[];
        commanders?: DeckCommander[];
        companion?: DeckCompanion | null;
        categories?: DeckCategoryRow[];
        format?: string;
        picks?: Record<string, string>;
        automaticSpell?: AutomaticSpell;
    } = {}
) =>
    useDeckStats(
        () => options.cards ?? [],
        () => options.commanders ?? [],
        () => options.companion ?? null,
        () => options.categories ?? [],
        () => options.format ?? "modern",
        () => options.picks ?? {},
        () => options.automaticSpell ?? "hardest"
    );

const plains = (quantity: number) =>
    makeDeckCard({
        name: "Plains",
        type_line: "Basic Land — Plains",
        cmc: 0,
        mana_cost: [null],
        is_basic_land: true,
        produced_mana: ["W"],
        quantity
    });

/**
 * A card whose only interesting property is its cost and mana value. Named
 * after its cost, so a Karsten row naming the card it is priced for reads
 * as the spell the test built (`"{1}{W}{W}"`).
 */
const spell = (mana_cost: string, cmc: number, overrides: Partial<DeckCardRow> = {}) =>
    makeDeckCard({ name: mana_cost, type_line: "Instant", mana_cost: [mana_cost], cmc, ...overrides });

/**
 * A mana rock with an explicitly colourless cost. The cost matters: a coloured
 * one would add a forced pip and quietly widen `usedColors`, which is exactly
 * what the production clamp is being measured against.
 */
const manaRock = (produced_mana: string[]) =>
    makeDeckCard({ type_line: "Artifact", mana_cost: ["{2}"], cmc: 2, produced_mana, mana_source: "ability" });

describe("useDeckStats — totalNonCommanderCards", () => {
    it("sums quantity across the deck, not row count", () => {
        const { totalNonCommanderCards } = setup({ cards: [plains(12), spell("{U}", 1, { quantity: 4 })] });

        expect(totalNonCommanderCards.value).toBe(16);
    });

    it("excludes commanders and the companion", () => {
        const { totalNonCommanderCards } = setup({
            cards: [plains(1)],
            commanders: [makeCommander()],
            companion: makeCompanion()
        });

        expect(totalNonCommanderCards.value).toBe(1);
    });
});

describe("useDeckStats — manaCurve", () => {
    it("always returns nine buckets, 0 through 8", () => {
        const { manaCurve } = setup();

        expect(manaCurve.value.map(b => b.cmc)).toEqual([0, 1, 2, 3, 4, 5, 6, 7, 8]);
        expect(manaCurve.value.every(b => b.total === 0)).toBe(true);
    });

    it("excludes lands", () => {
        const { manaCurve } = setup({ cards: [plains(12), spell("{U}", 1)] });

        expect(manaCurve.value[0].total).toBe(0);
        expect(manaCurve.value[1].total).toBe(1);
    });

    it("splits each bucket into permanents and non-permanents", () => {
        const { manaCurve } = setup({
            cards: [
                makeDeckCard({ type_line: "Creature — Bear", cmc: 2 }),
                makeDeckCard({ type_line: "Artifact", cmc: 2 }),
                makeDeckCard({ type_line: "Instant", cmc: 2 }),
                makeDeckCard({ type_line: "Sorcery", cmc: 2 })
            ]
        });

        expect(manaCurve.value[2]).toEqual({ cmc: 2, permanents: 2, spells: 2, total: 4 });
    });

    it("collapses everything from 8 mana upward into the last bucket", () => {
        const { manaCurve } = setup({
            cards: [spell("{7}{U}", 8), spell("{14}{U}", 15, { quantity: 2 })]
        });

        expect(manaCurve.value[8].total).toBe(3);
    });

    it("weights by quantity", () => {
        const { manaCurve } = setup({ cards: [spell("{U}", 1, { quantity: 4 })] });

        expect(manaCurve.value[1].total).toBe(4);
    });

    it("counts each commander once, since the command zone holds no duplicates", () => {
        const { manaCurve } = setup({
            commanders: [makeCommander({ cmc: 4, type_line: "Legendary Creature — Human" })]
        });

        expect(manaCurve.value[4]).toEqual({ cmc: 4, permanents: 1, spells: 0, total: 1 });
    });

    it("leaves the companion out — it is cast from outside the deck", () => {
        // Matches the Moxfield / Archidekt convention.
        const { manaCurve } = setup({ companion: makeCompanion({ cmc: 3 }) });

        expect(manaCurve.value.every(b => b.total === 0)).toBe(true);
    });

    it("counts both partners, not just the first", () => {
        const { manaCurve } = setup({
            commanders: [
                makeCommander({ name: "Thrasios, Triton Hero", cmc: 2, is_partner: true }),
                makeCommander({ name: "Tymna the Weaver", cmc: 3, is_partner: true })
            ]
        });

        expect(manaCurve.value[2].total).toBe(1);
        expect(manaCurve.value[3].total).toBe(1);
    });

    it("buckets an Oathbreaker signature spell as a spell, not a permanent", () => {
        const { manaCurve } = setup({ commanders: [makeCommander({ cmc: 2, type_line: "Sorcery" })] });

        expect(manaCurve.value[2]).toEqual({ cmc: 2, permanents: 0, spells: 1, total: 1 });
    });
});

describe("useDeckStats — averageManaValue", () => {
    it("is 0 for a deck with nothing to average", () => {
        expect(setup().averageManaValue.value).toBe(0);
        expect(setup({ cards: [plains(20)] }).averageManaValue.value).toBe(0);
    });

    it("weights by quantity", () => {
        // 4×1 + 1×5 = 9 over 5 cards.
        const { averageManaValue } = setup({ cards: [spell("{U}", 1, { quantity: 4 }), spell("{4}{U}", 5)] });

        expect(averageManaValue.value).toBeCloseTo(1.8, 10);
    });

    it("ignores lands, matching the curve's inclusion rule", () => {
        const { averageManaValue } = setup({ cards: [plains(20), spell("{1}{U}", 2)] });

        expect(averageManaValue.value).toBe(2);
    });

    it("includes commanders but not the companion", () => {
        const { averageManaValue } = setup({
            cards: [spell("{U}", 1)],
            commanders: [makeCommander({ cmc: 5 })],
            companion: makeCompanion({ cmc: 99 })
        });

        expect(averageManaValue.value).toBe(3);
    });
});

describe("useDeckStats — costPips", () => {
    it("is empty for a deck with no coloured costs", () => {
        expect(setup({ cards: [spell("{3}", 3)] }).costPips.value).toEqual(NO_PIPS);
    });

    it("counts repeated pips and weights by quantity", () => {
        const { costPips } = setup({ cards: [spell("{1}{W}{W}", 3, { quantity: 3 })] });

        expect(costPips.value).toEqual({ ...NO_PIPS, W: 6 });
    });

    it("counts hybrid pips under both colours, by design", () => {
        // The pip total can then exceed the deck's mana-value sum — the donut
        // is about "which colours does this deck want", not about totals.
        const { costPips } = setup({ cards: [spell("{W/U}", 1)] });

        expect(costPips.value).toEqual({ ...NO_PIPS, W: 1, U: 1 });
    });

    it("counts phyrexian and twobrid pips under their one colour", () => {
        const { costPips } = setup({ cards: [spell("{W/P}", 1), spell("{2/U}", 2)] });

        expect(costPips.value).toEqual({ ...NO_PIPS, W: 1, U: 1 });
    });

    it("ignores generic, colourless and snow symbols", () => {
        const { costPips } = setup({ cards: [spell("{X}{2}{C}{S}", 4)] });

        expect(costPips.value).toEqual(NO_PIPS);
    });

    it("skips lands even when they carry a cost string", () => {
        const { costPips } = setup({ cards: [makeDeckCard({ type_line: "Land — Gate", mana_cost: ["{W}"], cmc: 0 })] });

        expect(costPips.value).toEqual(NO_PIPS);
    });

    it("sums every face of a split or modal card", () => {
        const { costPips } = setup({
            cards: [makeDeckCard({ type_line: "Instant", mana_cost: ["{W}", "{U}"], cmc: 1 })]
        });

        expect(costPips.value).toEqual({ ...NO_PIPS, W: 1, U: 1 });
    });

    it("includes both commanders and the companion", () => {
        const { costPips } = setup({
            commanders: [makeCommander({ mana_cost: ["{R}"] })],
            companion: makeCompanion({ mana_cost: ["{G}"] })
        });

        expect(costPips.value).toEqual({ ...NO_PIPS, R: 1, G: 1 });
    });

    it("counts both partners' costs", () => {
        const { costPips } = setup({
            commanders: [
                makeCommander({ name: "Thrasios, Triton Hero", mana_cost: ["{1}{U}"], cmc: 2, is_partner: true }),
                makeCommander({ name: "Tymna the Weaver", mana_cost: ["{1}{W}{W}"], cmc: 3, is_partner: true })
            ],
            format: "commander"
        });

        expect(costPips.value).toEqual({ ...NO_PIPS, U: 1, W: 2 });
    });

    it("sums every face of a commander's cost", () => {
        const { costPips } = setup({
            commanders: [makeCommander({ mana_cost: ["{W}", "{U}"], cmc: 1 })],
            format: "commander"
        });

        expect(costPips.value).toEqual({ ...NO_PIPS, W: 1, U: 1 });
    });
});

describe("useDeckStats — productionPips", () => {
    it("counts produced mana, weighted by quantity", () => {
        const { productionPips } = setup({ cards: [plains(12), spell("{W}", 1)] });

        expect(productionPips.value).toEqual({ ...NO_PIPS, W: 12 });
    });

    it("drops colourless production, mirroring the cost side", () => {
        const { productionPips } = setup({
            cards: [makeDeckCard({ type_line: "Basic Land — Wastes", produced_mana: ["C"], cmc: 0 }), spell("{W}", 1)]
        });

        expect(productionPips.value).toEqual(NO_PIPS);
    });

    it("clamps production to the colours the deck actually asks for", () => {
        // A five-colour rock in a mono-white deck can only usefully make white.
        const { productionPips } = setup({
            cards: [spell("{W}", 1), manaRock(["W", "U", "B", "R", "G"])]
        });

        expect(productionPips.value).toEqual({ ...NO_PIPS, W: 1 });
    });

    it("also clamps to the commander colour identity", () => {
        const { productionPips } = setup({
            cards: [makeDeckCard({ type_line: "Land", produced_mana: ["W", "U", "B", "R", "G"], cmc: 0 })],
            commanders: [makeCommander({ color_identity: "RW", mana_cost: ["{R}{W}"], cmc: 2 })],
            format: "commander"
        });

        expect(productionPips.value).toEqual({ ...NO_PIPS, W: 1, R: 1 });
    });

    it("intersects the two clamps rather than applying only one", () => {
        // Bant commander, but the 99 only ever asks for white. Clamping by CI
        // alone would leave U and G; clamping by used colours alone would leave
        // nothing to test. The answer is the intersection: white only.
        const { productionPips } = setup({
            cards: [spell("{W}", 1), manaRock(["W", "U", "B", "R", "G"])],
            commanders: [makeCommander({ color_identity: "GWU", mana_cost: ["{2}"], cmc: 2 })],
            format: "commander"
        });

        expect(productionPips.value).toEqual({ ...NO_PIPS, W: 1 });
    });

    it("falls back to hybrid colours when the deck has no forced pip at all", () => {
        // A pure-hybrid build would otherwise collapse the donut to empty.
        const { productionPips } = setup({
            cards: [spell("{W/U}", 1), manaRock(["W", "U", "B", "R", "G"])]
        });

        expect(productionPips.value).toEqual({ ...NO_PIPS, W: 1, U: 1 });
    });

    it("resolves a fetchland against the deck's other lands", () => {
        // Scryfall reports no produced_mana for fetches; the effective colours
        // come from what the deck can actually fetch.
        const { productionPips } = setup({
            cards: [
                spell("{W}", 1),
                spell("{U}", 1),
                makeDeckCard({
                    name: "Flooded Strand",
                    type_line: "Land",
                    cmc: 0,
                    produced_mana: null,
                    fetch_pattern: "typed:WU"
                }),
                makeDeckCard({
                    name: "Hallowed Fountain",
                    type_line: "Land — Plains Island",
                    cmc: 0,
                    produced_mana: ["W", "U"]
                })
            ]
        });

        // One from the shockland, one from the fetch that can find it.
        expect(productionPips.value).toEqual({ ...NO_PIPS, W: 2, U: 2 });
    });

    it("leaves a fetchland at zero when the deck has nothing to fetch", () => {
        const { productionPips } = setup({
            cards: [
                spell("{W}", 1),
                makeDeckCard({ type_line: "Land", cmc: 0, produced_mana: null, fetch_pattern: "typed:WU" })
            ]
        });

        expect(productionPips.value).toEqual(NO_PIPS);
    });

    it("takes the colour identity from every partner, not just the first", () => {
        // A Thrasios + Tymna deck is four colours; clamping to one partner
        // would silently drop half the deck's production.
        const { productionPips } = setup({
            cards: [spell("{W}", 1), spell("{U}", 1), manaRock(["W", "U", "B", "R", "G"])],
            commanders: [
                makeCommander({
                    name: "Thrasios, Triton Hero",
                    color_identity: "GU",
                    mana_cost: ["{2}"],
                    is_partner: true
                }),
                makeCommander({ name: "Tymna the Weaver", color_identity: "WB", mana_cost: ["{2}"], is_partner: true })
            ],
            format: "commander"
        });

        expect(productionPips.value).toEqual({ ...NO_PIPS, W: 1, U: 1 });
    });

    it("counts production from commanders and the companion", () => {
        // Both are mana creatures — Karsten's half a source, one pip each here.
        const { productionPips } = setup({
            cards: [spell("{G}", 1)],
            commanders: [
                makeCommander({ color_identity: "G", produced_mana: ["G"], mana_source: "ability", mana_cost: ["{G}"] })
            ],
            companion: makeCompanion({ produced_mana: ["G"], mana_source: "ability" }),
            format: "commander"
        });

        expect(productionPips.value).toEqual({ ...NO_PIPS, G: 2 });
    });

    it("counts a land-search spell as a pip of every colour it can find", () => {
        // Three Visits finds a Forest card; the deck's only one is a Bayou.
        const { productionPips } = setup({ cards: [bayou(), threeVisits(2), spell("{2}{B}", 3)] });

        expect(productionPips.value).toEqual({ ...NO_PIPS, B: 3, G: 3 });
    });

    it("leaves out the cards Karsten does not count as sources", () => {
        // A ritual has produced_mana but fixes no later turn — the same cards
        // count here as under "Mana sources", only unweighted.
        const darkRitual = makeDeckCard({
            name: "Dark Ritual",
            type_line: "Instant",
            cmc: 1,
            mana_cost: ["{B}"],
            produced_mana: ["B"],
            mana_source: "ability",
            quantity: 4
        });
        const { productionPips } = setup({ cards: [plains(1), darkRitual, spell("{B}", 1)] });

        expect(productionPips.value).toEqual(NO_PIPS);
    });

    it("counts every source as one pip, however and whenever Karsten counts it", () => {
        // The rocks count nothing toward the white one-drop and Three Visits
        // only ¾ of a source toward the black three-drop, so the "Mana sources"
        // rows read lower than the ring — never higher.
        const { productionPips, karstenAnalysis } = setup({
            cards: [
                plains(10),
                bayou(),
                threeVisits(2),
                { ...manaRock(["W"]), quantity: 4 },
                spell("{W}", 1),
                spell("{3}{W}{W}", 5),
                spell("{2}{B}", 3)
            ]
        });

        expect(productionPips.value).toEqual({ ...NO_PIPS, W: 14, B: 3, G: 3 });
        expect(karstenAnalysis.value.map(row => [row.color, row.have])).toEqual([
            ["W", 10],
            ["B", 2.5],
            ["G", 1]
        ]);
    });
});

describe("useDeckStats — karstenAnalysis", () => {
    it("is empty for a deck with no coloured costs", () => {
        expect(setup({ cards: [spell("{3}", 3)] }).karstenAnalysis.value).toEqual([]);
    });

    it("reports the requirement of the deck's most demanding card in that colour", () => {
        const { karstenAnalysis } = setup({
            cards: [
                plains(12),
                spell("{W}", 1), // 1 pip at MV 1 → 14
                spell("{1}{W}{W}", 3) // 2 pips at MV 3 → 18, the max
            ]
        });

        expect(priced(karstenAnalysis.value)).toEqual([
            { color: "W", have: 12, need: 18, short: 6, card: "{1}{W}{W}", mv: 3 }
        ]);
    });

    it("reports no shortfall once the deck has enough sources", () => {
        const { karstenAnalysis } = setup({ cards: [plains(20), spell("{W}", 1)] });

        expect(priced(karstenAnalysis.value)).toEqual([
            { color: "W", have: 20, need: 14, short: 0, card: "{W}", mv: 1 }
        ]);
    });

    it("adds Karsten's +1 for a gold card, which needs both colours on curve", () => {
        const { karstenAnalysis } = setup({ cards: [spell("{1}{W}{U}", 3)] });

        // One pip each at MV 3 is 12; the gold hack makes it 13.
        expect(priced(karstenAnalysis.value)).toEqual([
            { color: "W", have: 0, need: 13, short: 13, card: "{1}{W}{U}", mv: 3 },
            { color: "U", have: 0, need: 13, short: 13, card: "{1}{W}{U}", mv: 3 }
        ]);
    });

    it("leaves pure-hybrid cards out — either colour pays, so neither is required", () => {
        const { karstenAnalysis } = setup({ cards: [spell("{3}{W/U}{W/U}", 5)] });

        expect(karstenAnalysis.value).toEqual([]);
    });

    it("ignores lands, whose own colour pips are not a casting requirement", () => {
        const { karstenAnalysis } = setup({
            cards: [makeDeckCard({ type_line: "Land — Gate", mana_cost: ["{W}"], cmc: 0 })]
        });

        expect(karstenAnalysis.value).toEqual([]);
    });

    it("uses the 99-card table for commander decks", () => {
        const modern = setup({ cards: [spell("{W}", 1)] }).karstenAnalysis.value;
        const commander = setup({ cards: [spell("{W}", 1)], format: "commander" }).karstenAnalysis.value;

        expect(modern[0].need).toBe(14);
        expect(commander[0].need).toBe(19);
    });

    it("counts commander and companion costs as requirements", () => {
        const { karstenAnalysis } = setup({
            commanders: [makeCommander({ mana_cost: ["{R}"], cmc: 1, color_identity: "R" })],
            companion: makeCompanion({ mana_cost: ["{G}"], cmc: 1 }),
            format: "commander"
        });

        expect(karstenAnalysis.value.map(r => r.color)).toEqual(["R", "G"]);
    });

    it("returns rows in WUBRG order", () => {
        const { karstenAnalysis } = setup({ cards: [spell("{G}", 1), spell("{U}", 1), spell("{W}", 1)] });

        expect(karstenAnalysis.value.map(r => r.color)).toEqual(["W", "U", "G"]);
    });

    it("skips a colour whose only demand falls in an unpublished table cell", () => {
        // One pip at MV 7 is the cell Karsten omitted as trivially satisfied.
        const { karstenAnalysis } = setup({ cards: [spell("{6}{W}", 7)] });

        expect(karstenAnalysis.value).toEqual([]);
    });
});

describe("useDeckStats — karstenCombined", () => {
    it("is empty for a deck with neither gold nor hybrid demand", () => {
        expect(setup({ cards: [spell("{1}{W}{W}", 3)] }).karstenCombined.value).toEqual([]);
    });

    it("adds a combined row for a gold card, with the +1 hack", () => {
        const { karstenCombined } = setup({ cards: [spell("{1}{W}{U}", 3)] });

        // Two total forced pips at MV 3 is 18, plus the gold +1.
        expect(priced(karstenCombined.value)).toEqual([
            { colors: ["W", "U"], have: 0, need: 19, short: 19, card: "{1}{W}{U}", mv: 3 }
        ]);
    });

    it("adds a combined row for a hybrid card, with no +1", () => {
        const { karstenCombined } = setup({ cards: [spell("{3}{W/U}{W/U}", 5)] });

        // Two hybrid pips at MV 5 is 15 — only one colour is needed per pip.
        expect(priced(karstenCombined.value)).toEqual([
            { colors: ["W", "U"], have: 0, need: 15, short: 15, card: "{3}{W/U}{W/U}", mv: 5 }
        ]);
    });

    it("treats repeated hybrid symbols as one requirement of that density", () => {
        const one = setup({ cards: [spell("{4}{W/U}", 5)] }).karstenCombined.value;
        const two = setup({ cards: [spell("{3}{W/U}{W/U}", 5)] }).karstenCombined.value;

        expect(one[0].need).toBe(9); // 1 pip at MV 5
        expect(two[0].need).toBe(15); // 2 pips at MV 5, not 2 × 9
    });

    it("merges cards demanding the same colour set, keeping the larger requirement", () => {
        const { karstenCombined } = setup({ cards: [spell("{1}{W}{U}", 3), spell("{W}{U}", 2)] });

        expect(karstenCombined.value).toHaveLength(1);
        expect(karstenCombined.value[0].need).toBe(22); // 2 pips at MV 2 → 21, +1
    });

    it("counts a producer once per copy if it can make any colour in the combo", () => {
        const { karstenCombined } = setup({
            cards: [
                spell("{1}{W}{U}", 3),
                plains(12), // white only, but that is inside the {W,U} combo
                makeDeckCard({ type_line: "Basic Land — Island", produced_mana: ["U"], cmc: 0, quantity: 8 })
            ]
        });

        expect(karstenCombined.value[0].have).toBe(20);
    });

    it("does not count a producer whose colours miss the combo entirely", () => {
        const { karstenCombined } = setup({
            cards: [
                spell("{1}{W}{U}", 3),
                makeDeckCard({ type_line: "Basic Land — Forest", produced_mana: ["G"], cmc: 0, quantity: 8 })
            ]
        });

        expect(karstenCombined.value[0].have).toBe(0);
    });

    it("counts the commander's own gold cost as a combined requirement", () => {
        const { karstenCombined } = setup({
            commanders: [makeCommander({ mana_cost: ["{W}{U}"], cmc: 2, color_identity: "WU" })],
            format: "commander"
        });

        expect(karstenCombined.value.map(r => r.colors.join(""))).toEqual(["WU"]);
    });

    it("counts the companion's hybrid cost too", () => {
        // Yorion is `{3}{W/U}{W/U}` — the deck has to support it even though it
        // is cast from outside the deck.
        const { karstenCombined } = setup({
            companion: makeCompanion({ name: "Yorion, Sky Nomad", mana_cost: ["{3}{W/U}{W/U}"], cmc: 5 })
        });

        expect(priced(karstenCombined.value)).toEqual([
            { colors: ["W", "U"], have: 0, need: 15, short: 15, card: "Yorion, Sky Nomad", mv: 5 }
        ]);
    });

    it("sorts by combo size first, ahead of the alphabet", () => {
        // "UBR" sorts before "WU" alphabetically, so a pure localeCompare would
        // put the three-colour combo first.
        const { karstenCombined } = setup({
            cards: [spell("{U}{B}{R}", 3), spell("{W}{U}", 2)]
        });

        expect(karstenCombined.value.map(r => r.colors.join(""))).toEqual(["WU", "UBR"]);
    });

    it("sorts same-size combos alphabetically", () => {
        const { karstenCombined } = setup({
            cards: [spell("{W}{U}", 2), spell("{U}{B}", 2)]
        });

        expect(karstenCombined.value.map(r => r.colors.join(""))).toEqual(["UB", "WU"]);
    });
});

/** A basic Forest — `basic` land-search patterns only see lands flagged basic. */
const forest = (quantity: number) =>
    makeDeckCard({
        name: "Forest",
        type_line: "Basic Land — Forest",
        cmc: 0,
        mana_cost: [null],
        is_basic_land: true,
        produced_mana: ["G"],
        quantity
    });

const bayou = () =>
    makeDeckCard({
        name: "Bayou",
        type_line: "Land — Swamp Forest",
        cmc: 0,
        mana_cost: [null],
        produced_mana: ["B", "G"]
    });

const threeVisits = (quantity: number) =>
    makeDeckCard({
        name: "Three Visits",
        type_line: "Sorcery",
        cmc: 2,
        mana_cost: ["{1}{G}"],
        land_search_pattern: "typed:G",
        quantity
    });

describe("useDeckStats — Karsten source weighting", () => {
    it("counts two-mana ramp as ¾ of every colour its lands can bring, from MV 3", () => {
        // Three Visits finds a Forest card; the deck's only one is a Bayou, so
        // each copy is ¾ of a black source for the three-drop — and nothing
        // toward its own two-mana cost.
        const { karstenAnalysis } = setup({ cards: [bayou(), threeVisits(3), spell("{2}{B}", 3)] });

        expect(priced(karstenAnalysis.value)).toEqual([
            { color: "B", have: 3.25, need: 12, short: 8.75, card: "{2}{B}", mv: 3 },
            { color: "G", have: 1, need: 13, short: 12, card: "Three Visits", mv: 2 }
        ]);
    });

    it("counts three-mana ramp as ¼ at MV 4 and ½ from MV 5", () => {
        const cultivate = makeDeckCard({
            name: "Cultivate",
            type_line: "Sorcery",
            cmc: 3,
            mana_cost: ["{2}{G}"],
            land_search_pattern: "basic",
            quantity: 2
        });

        const fiveDrop = setup({ cards: [forest(10), cultivate, spell("{3}{G}{G}", 5)] }).karstenAnalysis.value;
        const fourDrop = setup({ cards: [forest(10), cultivate, spell("{2}{G}{G}", 4)] }).karstenAnalysis.value;

        expect(priced(fiveDrop)).toEqual([{ color: "G", have: 11, need: 15, short: 4, card: "{3}{G}{G}", mv: 5 }]);
        expect(priced(fourDrop)).toEqual([{ color: "G", have: 10.5, need: 16, short: 5.5, card: "{2}{G}{G}", mv: 4 }]);
    });

    it("counts a mana creature as ½ of a source", () => {
        const elves = makeDeckCard({
            name: "Llanowar Elves",
            type_line: "Creature — Elf Druid",
            cmc: 1,
            mana_cost: ["{G}"],
            produced_mana: ["G"],
            mana_source: "ability",
            quantity: 4
        });
        const { karstenAnalysis } = setup({ cards: [forest(10), elves, spell("{1}{G}{G}", 3)] });

        expect(priced(karstenAnalysis.value)).toEqual([
            { color: "G", have: 12, need: 18, short: 6, card: "{1}{G}{G}", mv: 3 }
        ]);
    });

    it("counts a mana rock as ¾ of a source", () => {
        const { karstenAnalysis } = setup({
            cards: [plains(10), { ...manaRock(["W"]), quantity: 4 }, spell("{1}{W}{W}", 3)]
        });

        expect(priced(karstenAnalysis.value)).toEqual([
            { color: "W", have: 13, need: 18, short: 5, card: "{1}{W}{W}", mv: 3 }
        ]);
    });

    it("does not count a ritual as a source", () => {
        const darkRitual = makeDeckCard({
            name: "Dark Ritual",
            type_line: "Instant",
            cmc: 1,
            mana_cost: ["{B}"],
            produced_mana: ["B"],
            mana_source: "ability",
            quantity: 4
        });
        const swamps = makeDeckCard({
            type_line: "Basic Land — Swamp",
            cmc: 0,
            mana_cost: [null],
            produced_mana: ["B"],
            quantity: 10
        });
        const { karstenAnalysis } = setup({ cards: [swamps, darkRitual, spell("{2}{B}{B}", 4)] });

        expect(priced(karstenAnalysis.value)).toEqual([
            { color: "B", have: 10, need: 16, short: 6, card: "{2}{B}{B}", mv: 4 }
        ]);
    });

    it("counts a spell/land MDFC as a full land source", () => {
        const balaGed = makeDeckCard({
            name: "Bala Ged Recovery",
            type_line: "Sorcery",
            cmc: 3,
            mana_cost: ["{2}{G}", null],
            produced_mana: ["G"],
            is_mdfc_land: true
        });

        expect(priced(setup({ cards: [balaGed] }).karstenAnalysis.value)).toEqual([
            { color: "G", have: 1, need: 12, short: 11, card: "Bala Ged Recovery", mv: 3 }
        ]);
    });

    it("counts a Treasure maker as ¼ of a source", () => {
        const tithe = makeDeckCard({
            name: "Smothering Tithe",
            type_line: "Enchantment",
            cmc: 4,
            mana_cost: ["{3}{W}"],
            produced_mana: ["W", "U", "B", "R", "G"],
            mana_source: "token",
            quantity: 2
        });
        const { karstenAnalysis } = setup({ cards: [plains(10), tithe, spell("{3}{W}{W}", 5)] });

        expect(priced(karstenAnalysis.value)).toEqual([
            { color: "W", have: 10.5, need: 15, short: 4.5, card: "{3}{W}{W}", mv: 5 }
        ]);
    });

    it("breaks an equal shortfall toward the larger requirement", () => {
        // Eight colourless-cost dorks (½ each, from MV 2) lift the three-drop
        // to the same four-source gap the one-drop has; the larger need wins.
        const dorks = makeDeckCard({
            type_line: "Artifact Creature — Construct",
            cmc: 1,
            mana_cost: ["{1}"],
            produced_mana: ["W"],
            mana_source: "ability",
            quantity: 8
        });
        const { karstenAnalysis } = setup({ cards: [spell("{W}", 1), plains(10), dorks, spell("{1}{W}{W}", 3)] });

        expect(priced(karstenAnalysis.value)).toEqual([
            { color: "W", have: 14, need: 18, short: 4, card: "{1}{W}{W}", mv: 3 }
        ]);
    });

    it("shows the requirement the deck falls short on, not merely the largest one", () => {
        // Eight rocks carry the five-drop (12 + 6 ≥ 15) but cannot help the
        // one-drop, which only lands can cast on turn one (12 < 14).
        const { karstenAnalysis } = setup({
            cards: [plains(12), { ...manaRock(["W"]), quantity: 8 }, spell("{W}", 1), spell("{3}{W}{W}", 5)]
        });

        expect(priced(karstenAnalysis.value)).toEqual([
            { color: "W", have: 12, need: 14, short: 2, card: "{W}", mv: 1 }
        ]);
    });

    it("prices combined rows per card too", () => {
        // The Signets reach the three-drop gold card but not the two-drop.
        const signets = { ...manaRock(["W", "U"]), quantity: 4 };
        const { karstenCombined } = setup({
            cards: [plains(8), signets, spell("{1}{W}{U}", 3), spell("{W}{U}", 2)]
        });

        expect(priced(karstenCombined.value)).toEqual([
            { colors: ["W", "U"], have: 8, need: 22, short: 14, card: "{W}{U}", mv: 2 }
        ]);
    });

    it("clamps sources to the commander colour identity", () => {
        // An off-identity black card still demands black; the deck's swamps
        // cannot legally answer it in a mono-green commander deck.
        const { karstenAnalysis } = setup({
            cards: [
                makeDeckCard({ type_line: "Basic Land — Swamp", cmc: 0, produced_mana: ["B"], quantity: 5 }),
                spell("{B}", 1)
            ],
            commanders: [makeCommander({ color_identity: "G", mana_cost: ["{2}"], cmc: 2 })],
            format: "commander"
        });

        expect(priced(karstenAnalysis.value)).toEqual([
            { color: "B", have: 0, need: 19, short: 19, card: "{B}", mv: 1 }
        ]);
    });
});

describe("useDeckStats — typeCounts", () => {
    it("is empty for a deck with no cards", () => {
        expect(setup().typeCounts.value).toEqual([]);
    });

    it("counts a card under every type on its type line", () => {
        // Additive by design: percentages sum past 100%.
        const { typeCounts } = setup({ cards: [makeDeckCard({ type_line: "Artifact Creature — Golem", cmc: 3 })] });

        expect(typeCounts.value.map(b => b.key).sort()).toEqual(["Artifact", "Creature"]);
        expect(typeCounts.value.every(b => b.percent === 100)).toBe(true);
    });

    it("omits types with no cards", () => {
        const { typeCounts } = setup({ cards: [makeDeckCard({ type_line: "Instant" })] });

        expect(typeCounts.value.map(b => b.key)).toEqual(["Instant"]);
    });

    it("sorts by count descending and weights by quantity", () => {
        const { typeCounts } = setup({
            cards: [makeDeckCard({ type_line: "Instant" }), plains(12)]
        });

        expect(typeCounts.value.map(b => [b.key, b.count])).toEqual([
            ["Land", 12],
            ["Instant", 1]
        ]);
    });

    it("counts the 99 only — the command zone is not part of the breakdown", () => {
        // The commander shares a type with a card in the deck, so folding it in
        // would show up in that bucket's count as well as the denominator.
        const { typeCounts } = setup({
            cards: [makeDeckCard({ type_line: "Creature — Bear" }), plains(3)],
            commanders: [makeCommander({ type_line: "Legendary Creature — Dog" })]
        });

        expect(typeCounts.value.find(b => b.key === "Creature")).toMatchObject({ count: 1, percent: 25 });
    });
});

describe("useDeckStats — subtypeBreakdowns", () => {
    it("is empty for a deck with no cards", () => {
        expect(setup().subtypeBreakdowns.value).toEqual({});
    });

    it("counts a card under each of its subtypes", () => {
        const { subtypeBreakdowns } = setup({
            cards: [makeDeckCard({ type_line: "Legendary Creature — Human Soldier" })]
        });

        expect(subtypeBreakdowns.value.Creature.map(b => b.label).sort()).toEqual(["Human", "Soldier"]);
    });

    it("uses cards-of-this-type as the percent denominator", () => {
        const { subtypeBreakdowns } = setup({
            cards: [
                makeDeckCard({ type_line: "Creature — Human", quantity: 3 }),
                makeDeckCard({ type_line: "Creature — Elf" }),
                makeDeckCard({ type_line: "Instant" })
            ]
        });

        // 3 of 4 creatures, not 3 of 5 cards.
        expect(subtypeBreakdowns.value.Creature[0]).toMatchObject({ label: "Human", count: 3, percent: 75 });
    });

    it("collects subtype-less cards into their own bucket", () => {
        const { subtypeBreakdowns } = setup({ cards: [makeDeckCard({ type_line: "Artifact" })] });

        expect(subtypeBreakdowns.value.Artifact).toEqual([
            { key: "__no_subtype", label: "__no_subtype", count: 1, percent: 100 }
        ]);
    });

    it("sorts buckets by count, without pinning the subtype-less one", () => {
        const { subtypeBreakdowns } = setup({
            cards: [
                makeDeckCard({ type_line: "Artifact" }),
                makeDeckCard({ type_line: "Artifact — Equipment", quantity: 5 })
            ]
        });

        expect(subtypeBreakdowns.value.Artifact.map(b => b.key)).toEqual(["Equipment", "__no_subtype"]);
    });

    it("omits card types with no cards", () => {
        const { subtypeBreakdowns } = setup({ cards: [makeDeckCard({ type_line: "Instant" })] });

        expect(Object.keys(subtypeBreakdowns.value)).toEqual(["Instant"]);
    });

    it("includes commanders and the companion, unlike the type breakdown", () => {
        const { subtypeBreakdowns } = setup({
            commanders: [makeCommander({ type_line: "Legendary Creature — Dog" })],
            companion: makeCompanion({ type_line: "Legendary Creature — Cat" })
        });

        expect(subtypeBreakdowns.value.Creature.map(b => b.label).sort()).toEqual(["Cat", "Dog"]);
    });
});

describe("useDeckStats — categoryCounts", () => {
    it("is empty for a deck with no cards", () => {
        expect(setup().categoryCounts.value).toEqual([]);
    });

    it("counts cards per named category, sorted by label", () => {
        const ramp = makeCategory("Ramp");
        const draw = makeCategory("Draw");
        const { categoryCounts } = setup({
            cards: [
                makeDeckCard({ category_id: ramp.id, quantity: 3 }),
                makeDeckCard({ category_id: draw.id, quantity: 2 })
            ],
            categories: [ramp, draw]
        });

        expect(categoryCounts.value.map(b => [b.label, b.count])).toEqual([
            ["Draw", 2],
            ["Ramp", 3]
        ]);
    });

    it("omits categories with no cards", () => {
        const { categoryCounts } = setup({ cards: [makeDeckCard()], categories: [makeCategory("Ramp")] });

        expect(categoryCounts.value.map(b => b.label)).toEqual(["Uncategorized"]);
    });

    it("appends the uncategorised bucket after the named ones", () => {
        const zombies = makeCategory("Zombies");
        const { categoryCounts } = setup({
            cards: [makeDeckCard({ category_id: zombies.id }), makeDeckCard({ category_id: null, quantity: 4 })],
            categories: [zombies]
        });

        expect(categoryCounts.value.map(b => b.key)).toEqual([zombies.id, "__uncategorized"]);
        expect(categoryCounts.value[1].count).toBe(4);
    });

    it("computes percent against the whole 99", () => {
        const ramp = makeCategory("Ramp");
        const { categoryCounts } = setup({
            cards: [makeDeckCard({ category_id: ramp.id }), makeDeckCard({ category_id: null, quantity: 3 })],
            categories: [ramp]
        });

        expect(categoryCounts.value.map(b => [b.label, b.percent])).toEqual([
            ["Ramp", 25],
            ["Uncategorized", 75]
        ]);
    });
});

describe("useDeckStats — reactivity", () => {
    it("recomputes when the card list changes", () => {
        const cards = ref<DeckCardRow[]>([]);
        const { totalNonCommanderCards, manaCurve } = useDeckStats(
            () => cards.value,
            () => [],
            () => null,
            () => [],
            () => "modern"
        );

        expect(totalNonCommanderCards.value).toBe(0);

        cards.value = [spell("{U}", 1, { quantity: 4 })];

        expect(totalNonCommanderCards.value).toBe(4);
        expect(manaCurve.value[1].total).toBe(4);
    });

    it("recomputes the Karsten tables when the format changes", () => {
        const format = ref("modern");
        const { karstenAnalysis } = useDeckStats(
            () => [spell("{W}", 1)],
            () => [],
            () => null,
            () => [],
            () => format.value
        );

        expect(karstenAnalysis.value[0].need).toBe(14);

        format.value = "commander";

        expect(karstenAnalysis.value[0].need).toBe(19);
    });
});

describe("useDeckStats — Karsten and the sideboard", () => {
    const sidePlains = (quantity: number) => ({ ...plains(quantity), zone: "side" });

    it("counts no sideboard card as a source — the donut still does", () => {
        const { karstenAnalysis, productionPips } = setup({ cards: [plains(10), sidePlains(4), spell("{W}", 1)] });

        expect(karstenAnalysis.value[0].have).toBe(10);
        expect(productionPips.value.W).toBe(14);
    });

    it("never picks a sideboard spell automatically", () => {
        // The sideboard's {W}{W} two-drop needs more white than the main deck's
        // one-drop, but the automatic spell is a main-deck one.
        const { karstenAnalysis } = setup({
            cards: [plains(10), spell("{W}", 1), spell("{W}{W}", 2, { zone: "side" })]
        });

        expect(karstenAnalysis.value[0]).toMatchObject({ card: "{W}", mv: 1, picked: false });
    });

    it("gives a color only the sideboard asks for no row", () => {
        const { karstenAnalysis } = setup({ cards: [plains(10), spell("{W}", 1), spell("{U}", 1, { zone: "side" })] });

        expect(karstenAnalysis.value.map(row => row.color)).toEqual(["W"]);
    });

    it("resolves a fetchland for the Karsten sources against main-deck lands only", () => {
        const fetchland = makeDeckCard({ type_line: "Land", cmc: 0, produced_mana: null, fetch_pattern: "typed:WU" });
        const sideShock = makeDeckCard({
            type_line: "Land — Plains Island",
            cmc: 0,
            produced_mana: ["W", "U"],
            zone: "side"
        });
        const { karstenAnalysis } = setup({ cards: [fetchland, sideShock, spell("{U}", 1)] });

        expect(karstenAnalysis.value[0].have).toBe(0);
    });
});

describe("useDeckStats — picking the spell a Karsten row counts for", () => {
    /** Ten Plains, four Signets, a white one-drop and a white three-drop. */
    const deck = () => {
        const oneDrop = spell("{W}", 1);
        const threeDrop = spell("{1}{W}{W}", 3);
        const sideboarded = spell("{2}{W}{W}", 4, { zone: "side" });
        return {
            oneDrop,
            threeDrop,
            sideboarded,
            cards: [plains(10), { ...manaRock(["W"]), quantity: 4 }, oneDrop, threeDrop, sideboarded]
        };
    };

    it("shows the automatic spell, and names it as such, while nothing is picked", () => {
        const { cards, oneDrop, threeDrop, sideboarded } = deck();
        const [row] = setup({ cards }).karstenAnalysis.value;

        // The rocks help the three-drop, but not enough: 13 of 18 is short by
        // five, the one-drop's 10 of 14 only by four.
        expect(row).toMatchObject({ key: "W", card: "{1}{W}{W}", mv: 3, have: 13, picked: false });
        expect(row.automatic).toEqual({ card: "{1}{W}{W}", mv: 3, chosenBy: "hardest" });
        expect(row.candidates).toEqual(
            expect.arrayContaining([oneDrop.oracle_card_id, threeDrop.oracle_card_id, sideboarded.oracle_card_id])
        );
        expect(row.candidates).toHaveLength(3);
    });

    it("prices the row for a picked spell, keeping the automatic one on record", () => {
        // The rocks are not online for the one-drop.
        const { cards, oneDrop } = deck();
        const [row] = setup({ cards, picks: { W: oneDrop.oracle_card_id } }).karstenAnalysis.value;

        expect(row).toMatchObject({ card: "{W}", mv: 1, have: 10, need: 14, short: 4, picked: true });
        expect(row.automatic).toEqual({ card: "{1}{W}{W}", mv: 3, chosenBy: "hardest" });
    });

    it("checks a picked sideboard spell against the main deck's sources", () => {
        const { cards, sideboarded } = deck();
        const [row] = setup({ cards, picks: { W: sideboarded.oracle_card_id } }).karstenAnalysis.value;

        expect(row).toMatchObject({ card: "{2}{W}{W}", mv: 4, have: 13, picked: true });
    });

    it("falls back to automatic when the picked card no longer demands the color", () => {
        const { cards } = deck();
        const [row] = setup({ cards, picks: { W: "oracle-card-gone" } }).karstenAnalysis.value;

        expect(row).toMatchObject({ card: "{1}{W}{W}", picked: false });
    });

    it("keys a pick by row, so a pick for one color leaves the others alone", () => {
        // Blue's automatic spell is the {1}{U}{U}; the pick names the {U}.
        const blueOne = spell("{U}", 1);
        const blueThree = spell("{1}{U}{U}", 3);
        const { cards } = deck();
        const rows = setup({ cards: [...cards, blueOne, blueThree], picks: { U: blueOne.oracle_card_id } })
            .karstenAnalysis.value;

        expect(rows.map(row => [row.key, row.card, row.picked])).toEqual([
            ["W", "{1}{W}{W}", false],
            ["U", "{U}", true]
        ]);
    });

    it("lists a card once among the candidates however many rows it has", () => {
        const { cards, threeDrop } = deck();
        const second = { ...makeDeckCard(threeDrop), id: "deck-card-copy", zone: "side" };
        const [row] = setup({ cards: [...cards, second] }).karstenAnalysis.value;

        expect(row.candidates.filter(id => id === threeDrop.oracle_card_id)).toHaveLength(1);
    });

    it("prices a pick by the card's tightest requirement when it demands the combination twice", () => {
        // {W}{U}{W/U} asks for W∪U twice: as a gold card (3 forced pips at
        // MV 3, +1) and through its hybrid symbol (1 pip at MV 3, no +1).
        // Picking it must show the gold requirement, the tighter one.
        const both = spell("{W}{U}{W/U}", 3);
        const other = spell("{W/U}", 1);
        const [row] = setup({ cards: [plains(10), both, other], picks: { WU: both.oracle_card_id } }).karstenCombined
            .value;

        expect(row).toMatchObject({ key: "WU", card: "{W}{U}{W/U}", need: 19, picked: true });
        expect(row.candidates).toHaveLength(2);
    });

    it("picks a multicolor row's spell by the combination's key", () => {
        const gold = spell("{W}{U}", 2);
        const goldFour = spell("{2}{W}{U}", 4);
        const islands = makeDeckCard({
            name: "Island",
            type_line: "Basic Land — Island",
            cmc: 0,
            mana_cost: [null],
            produced_mana: ["U"],
            quantity: 10
        });
        const cards = [plains(10), islands, gold, goldFour];

        const automatic = setup({ cards }).karstenCombined.value[0];
        const picked = setup({ cards, picks: { WU: goldFour.oracle_card_id } }).karstenCombined.value[0];

        expect(automatic).toMatchObject({ key: "WU", card: "{W}{U}", picked: false });
        expect(picked).toMatchObject({ key: "WU", card: "{2}{W}{U}", mv: 4, picked: true });
        expect(picked.automatic).toEqual({ card: "{W}{U}", mv: 2, chosenBy: "hardest" });
    });
});

describe("useDeckStats — the automatic spell: hardest or easiest", () => {
    // Ten Plains and four Signets: the three-drop is short by five (13 of 18),
    // the one-drop by four (10 of 14), the four-drop by three (13 of 16).
    const cards = () => [
        plains(10),
        { ...manaRock(["W"]), quantity: 4 },
        spell("{W}", 1),
        spell("{1}{W}{W}", 3),
        spell("{2}{W}{W}", 4)
    ];

    it("shows the spell the deck is closest to failing by default", () => {
        expect(setup({ cards: cards() }).karstenAnalysis.value[0]).toMatchObject({ card: "{1}{W}{W}", short: 5 });
    });

    it("shows the spell with the most room to spare when asked for the easiest", () => {
        // Not simply the cheapest: the rocks lift the four-drop past the one-drop.
        const [row] = setup({ cards: cards(), automaticSpell: "easiest" }).karstenAnalysis.value;

        expect(row).toMatchObject({ card: "{2}{W}{W}", need: 16, have: 13, short: 3, picked: false });
        expect(row.automatic).toEqual({ card: "{2}{W}{W}", mv: 4, chosenBy: "easiest" });
    });

    it("breaks a tie toward the smaller requirement for the easiest, the larger for the hardest", () => {
        // Both spells are short by four: the one-drop has 10 of 14, the
        // four-drop 12 of 16 — four mana creatures add ½ each from MV 2.
        const dorks = makeDeckCard({
            type_line: "Creature — Elf Druid",
            mana_cost: ["{1}"],
            cmc: 1,
            produced_mana: ["W"],
            mana_source: "ability",
            quantity: 4
        });
        const deck = [plains(10), dorks, spell("{W}", 1), spell("{2}{W}{W}", 4)];

        expect(setup({ cards: deck }).karstenAnalysis.value[0]).toMatchObject({ card: "{2}{W}{W}", short: 4 });
        expect(setup({ cards: deck, automaticSpell: "easiest" }).karstenAnalysis.value[0]).toMatchObject({
            card: "{W}",
            short: 4
        });
    });

    it("leaves a picked spell alone, whichever way the automatic one is chosen", () => {
        const deck = cards();
        const threeDrop = deck[3];
        const [row] = setup({
            cards: deck,
            automaticSpell: "easiest",
            picks: { W: threeDrop.oracle_card_id }
        }).karstenAnalysis.value;

        expect(row).toMatchObject({ card: "{1}{W}{W}", picked: true });
        expect(row.automatic).toEqual({ card: "{2}{W}{W}", mv: 4, chosenBy: "easiest" });
    });

    it("shows the cheapest spell when asked — a commander included, however short it falls", () => {
        // The one-drop commander is the cheapest even though, with only ten
        // white sources for turn 1, it is short by nine of Karsten's 19.
        const yoshimaru = makeCommander({
            name: "Yoshimaru, Ever Faithful",
            color_identity: "W",
            mana_cost: ["{W}"],
            cmc: 1
        });
        const [row] = setup({
            cards: [plains(10), spell("{4}{W}", 5), spell("{1}{W}", 2)],
            commanders: [yoshimaru],
            format: "commander",
            automaticSpell: "cheapest"
        }).karstenAnalysis.value;

        expect(row).toMatchObject({ card: "Yoshimaru, Ever Faithful", mv: 1, need: 19, short: 9 });
    });

    it("breaks a tie on mana value toward fewer pips for the cheapest", () => {
        const [row] = setup({
            cards: [plains(10), spell("{W}{W}", 2), spell("{1}{W}", 2), spell("{3}{W}", 4)],
            automaticSpell: "cheapest"
        }).karstenAnalysis.value;

        expect(row).toMatchObject({ card: "{1}{W}", mv: 2 });
    });

    it("chooses the multicolor rows the same way", () => {
        const deck = [plains(10), spell("{W}{U}", 2), spell("{3}{W}{U}", 5)];

        expect(setup({ cards: deck }).karstenCombined.value[0].card).toBe("{W}{U}");
        expect(setup({ cards: deck, automaticSpell: "easiest" }).karstenCombined.value[0].card).toBe("{3}{W}{U}");
        expect(setup({ cards: deck, automaticSpell: "cheapest" }).karstenCombined.value[0].card).toBe("{W}{U}");
    });
});

describe("useDeckStats — the automatic spell: the commander", () => {
    // Ten Plains and ten Islands. The hardest white spell is the {W}{W}
    // two-drop (10 of 30); either commander asks for far fewer.
    const islands = makeDeckCard({
        name: "Island",
        type_line: "Basic Land — Island",
        cmc: 0,
        mana_cost: [null],
        is_basic_land: true,
        produced_mana: ["U"],
        quantity: 10
    });
    const cards = () => [plains(10), islands, spell("{W}{W}", 2), spell("{2}{U}", 3), spell("{1}{W}{U}", 3)];
    const primary = makeCommander({
        name: "Thalia, Guardian of Thraben",
        color_identity: "W",
        mana_cost: ["{1}{W}"],
        cmc: 2
    });
    const partner = makeCommander({
        name: "Ishai, Ojutai Dragonspeaker",
        color_identity: "WU",
        mana_cost: ["{2}{W}{U}"],
        cmc: 4,
        is_partner: true
    });
    const run = (automaticSpell: AutomaticSpell, commanders: DeckCommander[] = [primary, partner]) =>
        setup({ cards: cards(), commanders, format: "commander", automaticSpell });

    it("prices a row the commander demands for the commander", () => {
        const [white] = run("commander", [primary]).karstenAnalysis.value;

        expect(white).toMatchObject({ key: "W", card: "Thalia, Guardian of Thraben", mv: 2, need: 19, picked: false });
        expect(white.automatic).toEqual({ card: "Thalia, Guardian of Thraben", mv: 2, chosenBy: "commander" });
    });

    it("prices it for the primary commander under the plain choice, for the named one under a keyed choice", () => {
        expect(run("commander").karstenAnalysis.value[0].card).toBe("Thalia, Guardian of Thraben");
        expect(run(`commander:${partner.oracle_card_id}`).karstenAnalysis.value[0]).toMatchObject({
            card: "Ishai, Ojutai Dragonspeaker",
            mv: 4,
            need: 17
        });
    });

    it("falls back to the hardest spell in a row the commander does not demand", () => {
        const { karstenAnalysis, karstenCombined } = run("commander", [primary]);
        const blue = karstenAnalysis.value.find(row => row.key === "U")!;

        expect(blue).toMatchObject({ card: "{1}{W}{U}", picked: false });
        expect(blue.automatic.chosenBy).toBe("hardest");
        expect(karstenCombined.value[0]).toMatchObject({ key: "WU", card: "{1}{W}{U}" });
        expect(karstenCombined.value[0].automatic.chosenBy).toBe("hardest");
    });

    it("falls back to the hardest spell once the chosen commander has left the deck", () => {
        const [white] = run("commander:oracle-card-gone").karstenAnalysis.value;

        expect(white).toMatchObject({ card: "{W}{W}", mv: 2, need: 30 });
        expect(white.automatic.chosenBy).toBe("hardest");
    });

    it("leaves a picked spell alone", () => {
        const deck = cards();
        const [white] = setup({
            cards: deck,
            commanders: [primary],
            format: "commander",
            automaticSpell: "commander",
            picks: { W: deck[2].oracle_card_id }
        }).karstenAnalysis.value;

        expect(white).toMatchObject({ card: "{W}{W}", picked: true });
        expect(white.automatic).toEqual({ card: "Thalia, Guardian of Thraben", mv: 2, chosenBy: "commander" });
    });

    it("prices a multicolor row a gold commander demands for the commander", () => {
        // Ishai's W∪U needs 27, the deck's {1}{W}{U} 29 — the hardest would be the latter.
        const [combined] = run(`commander:${partner.oracle_card_id}`).karstenCombined.value;

        expect(combined).toMatchObject({ key: "WU", card: "Ishai, Ojutai Dragonspeaker", mv: 4, need: 27 });
        expect(combined.automatic.chosenBy).toBe("commander");
    });
});

describe("useDeckStats — the automatic spell: in Oathbreaker", () => {
    // Ten Islands. The hardest blue spell is the {U}{U}{U} (10 of 23);
    // the oathbreaker needs 18, the signature spell 14.
    const islands = makeDeckCard({
        name: "Island",
        type_line: "Basic Land — Island",
        cmc: 0,
        mana_cost: [null],
        is_basic_land: true,
        produced_mana: ["U"],
        quantity: 10
    });
    const narset = makeCommander({
        name: "Narset, Parter of Veils",
        type_line: "Legendary Planeswalker — Narset",
        color_identity: "U",
        mana_cost: ["{1}{U}{U}"],
        cmc: 3
    });
    const brainstorm = makeCommander({
        name: "Brainstorm",
        type_line: "Instant",
        color_identity: "U",
        mana_cost: ["{U}"],
        cmc: 1,
        is_partner: true
    });
    const blueRow = (automaticSpell: AutomaticSpell) =>
        setup({
            cards: [islands, spell("{U}{U}{U}", 3)],
            commanders: [narset, brainstorm],
            format: "oathbreaker",
            automaticSpell
        }).karstenAnalysis.value[0];

    it("names the oathbreaker as the oathbreaker, the signature spell as the signature spell", () => {
        expect(blueRow("commander").automatic).toEqual({
            card: "Narset, Parter of Veils",
            mv: 3,
            chosenBy: "oathbreaker"
        });
        expect(blueRow(`commander:${brainstorm.oracle_card_id}`).automatic).toEqual({
            card: "Brainstorm",
            mv: 1,
            chosenBy: "signature_spell"
        });
    });

    it("falls back to the hardest spell as elsewhere", () => {
        expect(blueRow("commander:oracle-card-gone").automatic).toEqual({
            card: "{U}{U}{U}",
            mv: 3,
            chosenBy: "hardest"
        });
    });
});
