import { describe, expect, it } from "vitest";
import { ref } from "vue";
import { makeDeckCard } from "@/test/factories/deckCard.ts";
import { useDeckGrouping } from "../useDeckGrouping.ts";
import type { DeckSort } from "../useDeckSort.ts";

const creature = (name: string, cmc = 2) => makeDeckCard({ name, cmc, type_line: "Creature — Human Soldier" });
const island = (quantity = 1) => makeDeckCard({ name: "Island", cmc: 0, type_line: "Basic Land — Island", quantity });

describe("useDeckGrouping", () => {
    it("returns nothing for an empty deck", () => {
        const { groups } = useDeckGrouping(() => []);

        expect(groups.value).toEqual([]);
    });

    it("omits groups with no cards, so consumers can v-for unguarded", () => {
        const { groups } = useDeckGrouping(() => [creature("Mother of Runes")]);

        expect(groups.value.map(g => g.group)).toEqual(["creature"]);
    });

    it("returns groups in canonical display order, not input order", () => {
        const { groups } = useDeckGrouping(() => [
            island(),
            makeDeckCard({ name: "Counterspell", type_line: "Instant" }),
            creature("Grizzly Bears")
        ]);

        // GROUP_ORDER puts land first internally; useDeckGrouping emits in that
        // order, and `useDeckSections` is what later moves lands to the end.
        expect(groups.value.map(g => g.group)).toEqual(["land", "creature", "instant"]);
    });

    it("sums quantity into `count` rather than counting rows", () => {
        const { groups } = useDeckGrouping(() => [island(11), makeDeckCard({ type_line: "Basic Land — Plains" })]);

        const [land] = groups.value;
        expect(land.cards).toHaveLength(2);
        expect(land.count).toBe(12);
    });

    /**
     * Alphabetical order and mana order disagree on this set, so neither sort
     * mode can pass by accident.
     */
    const disagreeing = () => [
        creature("Ancient Copper Dragon", 6),
        creature("Wall of Omens", 2),
        creature("Birds of Paradise", 1)
    ];

    it("sorts within each group by mana value by default", () => {
        const { groups } = useDeckGrouping(disagreeing);

        expect(groups.value[0].cards.map(c => c.name)).toEqual([
            "Birds of Paradise",
            "Wall of Omens",
            "Ancient Copper Dragon"
        ]);
    });

    it("honours a name sort mode", () => {
        const { groups } = useDeckGrouping(disagreeing, () => "name");

        expect(groups.value[0].cards.map(c => c.name)).toEqual([
            "Ancient Copper Dragon",
            "Birds of Paradise",
            "Wall of Omens"
        ]);
    });

    it("re-sorts when the sort mode ref changes", () => {
        const sortMode = ref<DeckSort>("mana");
        const { groups } = useDeckGrouping(
            () => [creature("Ancestor's Chosen", 7), creature("Zealous Persecution", 2)],
            sortMode
        );

        expect(groups.value[0].cards.map(c => c.name)).toEqual(["Zealous Persecution", "Ancestor's Chosen"]);

        sortMode.value = "name";

        expect(groups.value[0].cards.map(c => c.name)).toEqual(["Ancestor's Chosen", "Zealous Persecution"]);
    });

    it("recomputes when the card list changes", () => {
        const cards = ref([creature("Mother of Runes")]);
        const { groups } = useDeckGrouping(cards);

        expect(groups.value).toHaveLength(1);

        cards.value = [...cards.value, island()];

        expect(groups.value.map(g => g.group)).toEqual(["land", "creature"]);
    });

    it("accepts a plain array as well as a ref or getter", () => {
        const { groups } = useDeckGrouping([creature("Mother of Runes")]);

        expect(groups.value.map(g => g.group)).toEqual(["creature"]);
    });

    it("buckets an unrecognised type line into `other`", () => {
        const { groups } = useDeckGrouping(() => [makeDeckCard({ type_line: "Scheme" })]);

        expect(groups.value.map(g => g.group)).toEqual(["other"]);
    });
});
