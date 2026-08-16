import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import type { DeckCardCount as DeckCardCountShape } from "Types/deckPage.ts";
import DeckCardCount from "../DeckCardCount.vue";

const count = (main: number, companion = 0, side = 0): DeckCardCountShape => ({ main, companion, side });

const render = (value: DeckCardCountShape) => mount(DeckCardCount, { props: { count: value } });

describe("DeckCardCount — visible counts", () => {
    it("shows the mainboard count on its own for a deck with nothing else", () => {
        const wrapper = render(count(100));

        expect(wrapper.find(".deck-card-count__main").text()).toBe("100");
        expect(wrapper.find(".deck-card-count__companion").exists()).toBe(false);
        expect(wrapper.find(".deck-card-count__side").exists()).toBe(false);
    });

    it("adds the companion with a plus", () => {
        const wrapper = render(count(99, 1));

        expect(wrapper.find(".deck-card-count__companion").text()).toBe("+ 1");
    });

    it("adds the sideboard behind a slash", () => {
        const wrapper = render(count(60, 0, 15));

        expect(wrapper.find(".deck-card-count__side").text()).toBe("/ 15");
    });

    it("shows all three at once", () => {
        expect(
            render(count(60, 1, 15))
                .text()
                .replace(/\s+/g, " ")
                .trim()
        ).toBe("60 + 1 / 15");
    });

    it("hides an empty companion or sideboard rather than printing a zero", () => {
        const wrapper = render(count(60, 0, 0));

        expect(wrapper.findAll(".deck-card-count__companion")).toHaveLength(0);
        expect(wrapper.findAll(".deck-card-count__side")).toHaveLength(0);
    });

    it("still renders a zero mainboard, so an empty deck shows a count", () => {
        expect(render(count(0)).find(".deck-card-count__main").text()).toBe("0");
    });
});

describe("DeckCardCount — tooltip", () => {
    it("lists only the parts the deck actually has", () => {
        const lines = (render(count(100)).attributes("data-tooltip") ?? "").split("<br />");

        expect(lines).toEqual(["pages.deck.card_count_tooltip.title", "pages.deck.card_count_tooltip.main"]);
    });

    it("adds a line per non-empty part", () => {
        const lines = (render(count(60, 1, 15)).attributes("data-tooltip") ?? "").split("<br />");

        expect(lines).toEqual([
            "pages.deck.card_count_tooltip.title",
            "pages.deck.card_count_tooltip.main",
            "pages.deck.card_count_tooltip.companion",
            "pages.deck.card_count_tooltip.side"
        ]);
    });

    it("joins the lines with a break, since FloatingVue renders it as HTML", () => {
        expect(render(count(60, 0, 15)).attributes("data-tooltip")).toContain("<br />");
    });
});
