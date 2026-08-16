import { describe, expect, it } from "vitest";
import { ref } from "vue";
import { makeCategory, makeCommander, makeCompanion, makeDeckCard } from "@/test/factories/deckCard.ts";
import type { DeckCardGroup } from "@/utils/deckGrouping.ts";
import { useDeckSections } from "../useDeckSections.ts";
import type { DeckSort } from "../useDeckSort.ts";

/**
 * Stand-in for `$t`. Returns the last dot-segment title-cased, so the
 * alphabetical ordering the composable applies to *labels* is exercised with
 * something label-shaped rather than the raw key.
 */
const translate = (key: string): string => {
    const last = key.split(".").pop() ?? key;
    return last.charAt(0).toUpperCase() + last.slice(1);
};

const creature = (name: string) => makeDeckCard({ name, type_line: "Creature — Human", cmc: 2 });
const instant = (name: string) => makeDeckCard({ name, type_line: "Instant", cmc: 1 });
const land = (name: string) => makeDeckCard({ name, type_line: "Basic Land — Island", cmc: 0 });

/** Call the composable with everything defaulted; override what a test needs. */
const setup = (
    options: {
        cards?: ReturnType<typeof makeDeckCard>[];
        commanders?: ReturnType<typeof makeCommander>[];
        companion?: ReturnType<typeof makeCompanion> | null;
        categories?: ReturnType<typeof makeCategory>[];
        allowsSideboard?: boolean;
        draggedTypeGroup?: DeckCardGroup | null;
        sortMode?: DeckSort;
    } = {}
) => {
    const dragged = ref<DeckCardGroup | null>(options.draggedTypeGroup ?? null);
    const sortMode = ref<DeckSort>(options.sortMode ?? "name");

    return {
        dragged,
        sortMode,
        ...useDeckSections(
            () => options.cards ?? [],
            () => options.commanders ?? [],
            () => options.companion ?? null,
            () => options.categories ?? [],
            sortMode,
            () => options.allowsSideboard ?? true,
            translate,
            dragged
        )
    };
};

describe("useDeckSections — allGroups", () => {
    it("is empty for a deck with no cards", () => {
        expect(setup().allGroups.value).toEqual([]);
    });

    it("orders groups by translated label, alphabetically", () => {
        // GROUP_ORDER puts instant ahead of artifact; the alphabet does the
        // opposite, so this can only pass if labels drive the order.
        const { allGroups } = setup({
            cards: [instant("Brainstorm"), makeDeckCard({ name: "Sol Ring", type_line: "Artifact", cmc: 1 })]
        });

        expect(allGroups.value.map(g => g.key)).toEqual(["artifact", "instant"]);
    });

    it("always puts the land group last, whatever the alphabet says", () => {
        // "Land" sorts before "Sorcery", so alphabetical order alone would put
        // the lands in the middle.
        const { allGroups } = setup({
            cards: [land("Island"), makeDeckCard({ name: "Ponder", type_line: "Sorcery", cmc: 1 }), creature("Bear")]
        });

        expect(allGroups.value.map(g => g.key)).toEqual(["creature", "sorcery", "land"]);
    });

    it("tags each group with the zone and category the drag handler will send", () => {
        // `useDeckCardDrag` PATCHes `categoryId` and `zone` straight off the
        // section it was dropped on, so a wrong value here silently
        // un-categorises or re-zones the card.
        const ramp = makeCategory("Ramp");
        const { allGroups } = setup({
            cards: [
                creature("Bear"),
                makeDeckCard({ name: "Llanowar Elves", type_line: "Creature — Elf", category_id: ramp.id }),
                makeDeckCard({ name: "Boil", type_line: "Instant", zone: "side" })
            ],
            categories: [ramp]
        });

        const groups = Object.fromEntries(allGroups.value.map(g => [g.key, g]));
        expect(groups.creature).toMatchObject({ categoryId: null, zone: "main" });
        expect(groups[`cat-${ramp.id}`]).toMatchObject({ categoryId: ramp.id, zone: "main" });
        expect(groups.side).toMatchObject({ categoryId: null, zone: "side" });
    });

    it("keeps sideboard cards out of the type groups", () => {
        const { allGroups } = setup({
            cards: [creature("Bear"), makeDeckCard({ name: "Boil", type_line: "Instant", zone: "side" })]
        });

        const groups = Object.fromEntries(allGroups.value.map(g => [g.key, g]));
        expect(Object.keys(groups)).toEqual(["creature", "side"]);
        expect(groups.side.cards.map(c => c.name)).toEqual(["Boil"]);
        expect(groups.side.zone).toBe("side");
    });

    it("aggregates the whole sideboard into one bucket regardless of card type", () => {
        const { allGroups } = setup({
            cards: [
                makeDeckCard({ name: "Boil", type_line: "Instant", zone: "side" }),
                makeDeckCard({ name: "Bear", type_line: "Creature — Bear", zone: "side", quantity: 3 })
            ]
        });

        const [side] = allGroups.value;
        expect(side.key).toBe("side");
        expect(side.cards).toHaveLength(2);
        expect(side.count).toBe(4);
    });

    it("hides the sideboard entirely in a format that disallows one", () => {
        const { allGroups } = setup({
            cards: [makeDeckCard({ name: "Boil", type_line: "Instant", zone: "side" })],
            allowsSideboard: false
        });

        expect(allGroups.value).toEqual([]);
    });

    it("sorts type groups and the sideboard by the active mode too", () => {
        const cards = [
            makeDeckCard({ name: "Ancient Copper Dragon", type_line: "Creature — Dragon", cmc: 6 }),
            makeDeckCard({ name: "Arbor Elf", type_line: "Creature — Elf", cmc: 1 }),
            makeDeckCard({ name: "Boil", type_line: "Instant", cmc: 1, zone: "side" }),
            makeDeckCard({ name: "Abrade", type_line: "Instant", cmc: 2, zone: "side" })
        ];

        // Both pairs are chosen so the alphabet and the curve disagree.
        const byName = setup({ cards, sortMode: "name" });
        const byMana = setup({ cards, sortMode: "mana" });
        const namesIn = (groups: { key: string; cards: { name: string }[] }[], key: string): string[] =>
            groups.find(g => g.key === key)?.cards.map(c => c.name) ?? [];

        expect(namesIn(byName.allGroups.value, "creature")).toEqual(["Ancient Copper Dragon", "Arbor Elf"]);
        expect(namesIn(byMana.allGroups.value, "creature")).toEqual(["Arbor Elf", "Ancient Copper Dragon"]);
        expect(namesIn(byName.allGroups.value, "side")).toEqual(["Abrade", "Boil"]);
        expect(namesIn(byMana.allGroups.value, "side")).toEqual(["Boil", "Abrade"]);
    });

    it("hides an empty sideboard even when the format allows one", () => {
        const { allGroups } = setup({ cards: [creature("Bear")], allowsSideboard: true });

        expect(allGroups.value.map(g => g.key)).toEqual(["creature"]);
    });

    describe("custom categories", () => {
        it("moves a categorised card out of its type group and into the category", () => {
            const ramp = makeCategory("Ramp");
            const { allGroups } = setup({
                cards: [
                    creature("Bear"),
                    makeDeckCard({ name: "Llanowar Elves", type_line: "Creature — Elf", category_id: ramp.id })
                ],
                categories: [ramp]
            });

            const groups = Object.fromEntries(allGroups.value.map(g => [g.key, g]));
            expect(groups.creature.cards.map(c => c.name)).toEqual(["Bear"]);
            expect(groups[`cat-${ramp.id}`].cards.map(c => c.name)).toEqual(["Llanowar Elves"]);
        });

        it("omits a category that has no cards", () => {
            const { allGroups } = setup({ cards: [creature("Bear")], categories: [makeCategory("Ramp")] });

            expect(allGroups.value.map(g => g.key)).toEqual(["creature"]);
        });

        it("sorts categories into the same alphabetical run as type groups", () => {
            const draw = makeCategory("Draw");
            const { allGroups } = setup({
                cards: [
                    instant("Brainstorm"),
                    makeDeckCard({ name: "Ponder", type_line: "Sorcery", category_id: draw.id })
                ],
                categories: [draw]
            });

            // "Draw" < "Instant".
            expect(allGroups.value.map(g => g.label)).toEqual(["Draw", "Instant"]);
        });

        it("keeps a category named like a land group in its alphabetical slot", () => {
            const lands = makeCategory("Lands");
            const { allGroups } = setup({
                cards: [
                    land("Island"),
                    makeDeckCard({ name: "Wasteland", type_line: "Land", category_id: lands.id }),
                    creature("Bear")
                ],
                categories: [lands]
            });

            // Only the auto-derived `land` group is forced last; the custom
            // "Lands" category sorts normally between Creature and (moved) Land.
            expect(allGroups.value.map(g => g.label)).toEqual(["Creature", "Lands", "Land"]);
        });

        it("drops a card whose category_id matches no known category", () => {
            const { allGroups } = setup({
                cards: [makeDeckCard({ name: "Orphan", type_line: "Instant", category_id: "category-deleted" })],
                categories: []
            });

            expect(allGroups.value).toEqual([]);
        });

        it("sorts the cards inside a category by the active mode", () => {
            // The two orders disagree on this pair, so neither mode can pass by
            // accident.
            const ramp = makeCategory("Ramp");
            const cards = [
                makeDeckCard({
                    name: "Ancient Copper Dragon",
                    type_line: "Creature — Dragon",
                    cmc: 6,
                    category_id: ramp.id
                }),
                makeDeckCard({ name: "Arbor Elf", type_line: "Creature — Elf", cmc: 1, category_id: ramp.id })
            ];

            const byName = setup({ cards, categories: [ramp], sortMode: "name" });
            const byMana = setup({ cards, categories: [ramp], sortMode: "mana" });

            expect(byName.allGroups.value[0].cards.map(c => c.name)).toEqual(["Ancient Copper Dragon", "Arbor Elf"]);
            expect(byMana.allGroups.value[0].cards.map(c => c.name)).toEqual(["Arbor Elf", "Ancient Copper Dragon"]);
        });

        it("ignores the category on a sideboard card", () => {
            const ramp = makeCategory("Ramp");
            const { allGroups } = setup({
                cards: [makeDeckCard({ name: "Boil", type_line: "Instant", zone: "side", category_id: ramp.id })],
                categories: [ramp]
            });

            expect(allGroups.value.map(g => g.key)).toEqual(["side"]);
        });
    });
});

describe("useDeckSections — sections", () => {
    it("prepends commanders and the companion, in that order", () => {
        const { sections } = setup({
            cards: [creature("Bear")],
            commanders: [makeCommander()],
            companion: makeCompanion()
        });

        expect(sections.value.map(s => s.kind)).toEqual(["commanders", "companion", "group"]);
    });

    it("omits the commander section for a deck with no command zone", () => {
        const { sections } = setup({ cards: [creature("Bear")] });

        expect(sections.value.map(s => s.kind)).toEqual(["group"]);
    });

    it("emits one commanders section holding both partners, not one each", () => {
        const { sections } = setup({
            commanders: [makeCommander({ is_partner: true }), makeCommander({ is_partner: true })]
        });

        const [first] = sections.value;
        expect(sections.value).toHaveLength(1);
        expect(first.kind === "commanders" && first.commanders).toHaveLength(2);
    });
});

describe("useDeckSections — dragTargets", () => {
    it("is empty while nothing is being dragged", () => {
        const { dragTargets } = setup({ cards: [creature("Bear")], categories: [makeCategory("Ramp")] });

        expect(dragTargets.value).toEqual([]);
    });

    it("surfaces empty categories once a drag starts, so every category is reachable", () => {
        const { dragTargets } = setup({
            cards: [creature("Bear")],
            categories: [makeCategory("Ramp")],
            draggedTypeGroup: "creature"
        });

        // The empty sideboard rides along — see the sideboard cases below.
        expect(dragTargets.value.map(t => t.label)).toEqual(["Ramp", "Side"]);
    });

    it("does not repeat a category that already has cards", () => {
        const ramp = makeCategory("Ramp");
        const { dragTargets } = setup({
            cards: [makeDeckCard({ name: "Llanowar Elves", type_line: "Creature — Elf", category_id: ramp.id })],
            categories: [ramp],
            draggedTypeGroup: "creature"
        });

        // `cat-…` is absent because the category already appears in allGroups;
        // `creature` is the placeholder for the dragged card's own type.
        expect(dragTargets.value.map(t => t.key)).toEqual(["creature", "side"]);
    });

    it("adds a placeholder for the dragged card's own type group when it is missing", () => {
        const ramp = makeCategory("Ramp");
        const { dragTargets } = setup({
            // The only creature lives in a category, so there is no `creature`
            // group to drag it back to.
            cards: [makeDeckCard({ name: "Llanowar Elves", type_line: "Creature — Elf", category_id: ramp.id })],
            categories: [ramp],
            draggedTypeGroup: "creature"
        });

        const placeholder = dragTargets.value.find(t => t.key === "creature");
        expect(placeholder).toMatchObject({ cards: [], count: 0, categoryId: null, zone: "main" });
    });

    it("puts a land placeholder after the alphabetical category targets", () => {
        const { dragTargets } = setup({
            cards: [creature("Bear")],
            categories: [makeCategory("Ramp"), makeCategory("Zombies")],
            draggedTypeGroup: "land"
        });

        expect(dragTargets.value.map(t => t.label)).toEqual(["Ramp", "Zombies", "Land", "Side"]);
    });

    it("offers an empty sideboard as a drop target during a drag", () => {
        const { dragTargets } = setup({ cards: [creature("Bear")], draggedTypeGroup: "creature" });

        expect(dragTargets.value.map(t => t.key)).toEqual(["side"]);
    });

    it("offers no sideboard target in a format that disallows one", () => {
        const { dragTargets } = setup({
            cards: [creature("Bear")],
            allowsSideboard: false,
            draggedTypeGroup: "creature"
        });

        expect(dragTargets.value).toEqual([]);
    });

    it("does not repeat the sideboard when it already holds cards", () => {
        const { dragTargets } = setup({
            cards: [creature("Bear"), makeDeckCard({ name: "Boil", type_line: "Instant", zone: "side" })],
            draggedTypeGroup: "creature"
        });

        expect(dragTargets.value).toEqual([]);
    });

    it("clears again when the drag ends", () => {
        const { dragTargets, dragged } = setup({ cards: [creature("Bear")], draggedTypeGroup: "creature" });

        expect(dragTargets.value).not.toEqual([]);

        dragged.value = null;

        expect(dragTargets.value).toEqual([]);
    });

    it("leaves the column-driving sections untouched while dragging", () => {
        // The whole reason dragTargets is separate: changing `sections`
        // mid-drag redistributes columns, destroying the active VueDraggable
        // and swallowing its @end event.
        const { sections, dragTargets, dragged } = setup({
            cards: [creature("Bear")],
            categories: [makeCategory("Ramp")]
        });
        const before = sections.value;

        dragged.value = "creature";

        expect(sections.value).toEqual(before);
        expect(dragTargets.value).not.toEqual([]);
    });
});
