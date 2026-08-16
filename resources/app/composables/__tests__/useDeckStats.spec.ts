import { describe, expect, it } from "vitest";
import { ref } from "vue";
import { makeCategory, makeCommander, makeCompanion, makeDeckCard } from "@/test/factories/deckCard.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion } from "Types/deckPage.ts";
import { useDeckStats } from "../useDeckStats.ts";

/** Zero pips in every colour — the baseline every tally assertion starts from. */
const NO_PIPS = { W: 0, U: 0, B: 0, R: 0, G: 0 };

/** Call the composable with everything defaulted; pass only what a test needs. */
const setup = (
    options: {
        cards?: DeckCardRow[];
        commanders?: DeckCommander[];
        companion?: DeckCompanion | null;
        categories?: DeckCategoryRow[];
        format?: string;
    } = {}
) =>
    useDeckStats(
        () => options.cards ?? [],
        () => options.commanders ?? [],
        () => options.companion ?? null,
        () => options.categories ?? [],
        () => options.format ?? "modern"
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

/** A card whose only interesting property is its cost and mana value. */
const spell = (mana_cost: string, cmc: number, overrides: Partial<DeckCardRow> = {}) =>
    makeDeckCard({ type_line: "Instant", mana_cost: [mana_cost], cmc, ...overrides });

/**
 * A mana rock with an explicitly colourless cost. The cost matters: a coloured
 * one would add a forced pip and quietly widen `usedColors`, which is exactly
 * what the production clamp is being measured against.
 */
const manaRock = (produced_mana: string[]) =>
    makeDeckCard({ type_line: "Artifact", mana_cost: ["{2}"], cmc: 2, produced_mana });

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
                makeCommander({ name: "Thrasios, Triton Hero", color_identity: "GU", mana_cost: ["{2}"], is_partner: true }),
                makeCommander({ name: "Tymna the Weaver", color_identity: "WB", mana_cost: ["{2}"], is_partner: true })
            ],
            format: "commander"
        });

        expect(productionPips.value).toEqual({ ...NO_PIPS, W: 1, U: 1 });
    });

    it("counts production from commanders and the companion", () => {
        const { productionPips } = setup({
            cards: [spell("{G}", 1)],
            commanders: [makeCommander({ color_identity: "G", produced_mana: ["G"], mana_cost: ["{G}"] })],
            companion: makeCompanion({ produced_mana: ["G"] }),
            format: "commander"
        });

        expect(productionPips.value).toEqual({ ...NO_PIPS, G: 2 });
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

        expect(karstenAnalysis.value).toEqual([{ color: "W", have: 12, need: 18, short: 6 }]);
    });

    it("reports no shortfall once the deck has enough sources", () => {
        const { karstenAnalysis } = setup({ cards: [plains(20), spell("{W}", 1)] });

        expect(karstenAnalysis.value).toEqual([{ color: "W", have: 20, need: 14, short: 0 }]);
    });

    it("adds Karsten's +1 for a gold card, which needs both colours on curve", () => {
        const { karstenAnalysis } = setup({ cards: [spell("{1}{W}{U}", 3)] });

        // One pip each at MV 3 is 12; the gold hack makes it 13.
        expect(karstenAnalysis.value).toEqual([
            { color: "W", have: 0, need: 13, short: 13 },
            { color: "U", have: 0, need: 13, short: 13 }
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
        expect(karstenCombined.value).toEqual([{ colors: ["W", "U"], have: 0, need: 19, short: 19 }]);
    });

    it("adds a combined row for a hybrid card, with no +1", () => {
        const { karstenCombined } = setup({ cards: [spell("{3}{W/U}{W/U}", 5)] });

        // Two hybrid pips at MV 5 is 15 — only one colour is needed per pip.
        expect(karstenCombined.value).toEqual([{ colors: ["W", "U"], have: 0, need: 15, short: 15 }]);
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

        expect(karstenCombined.value).toEqual([{ colors: ["W", "U"], have: 0, need: 15, short: 15 }]);
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
