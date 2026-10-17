import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { defineComponent, h } from "vue";
import { setTestMessages } from "@/test/i18n.ts";
import { type DeckHighlightApi, provideDeckHighlight } from "Composables/useDeckHighlight.ts";
import DeckStatsColors from "../DeckStatsColors.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const NO_PIPS = { W: 0, U: 0, B: 0, R: 0, G: 0 };

/** A Gruul deck: 20 red and 30 green pips in its costs, 23 red and 34 green producing cards. */
const props = {
    cost: { ...NO_PIPS, R: 20, G: 30 },
    production: { ...NO_PIPS, R: 23, G: 34 },
    sourcesShown: true
};

/** Mount the panel under a highlight provider and hand back the api it drives. */
const render = (overrides: Partial<typeof props> = {}) => {
    let api!: DeckHighlightApi;
    const Host = defineComponent({
        setup() {
            api = provideDeckHighlight();
            return () => h(DeckStatsColors, { ...props, ...overrides });
        }
    });
    return { wrapper: mount(Host), api: () => api };
};

describe("DeckStatsColors — production ring", () => {
    it("selects a production colour, not a spell's sources, so every source highlights", async () => {
        const { wrapper, api } = render();
        api().setSpellSources({ colors: ["R"], mv: 3 });

        await wrapper.get(".color-donut__ring--inner .color-donut__segment--R").trigger("click");

        expect(api().highlight.value.colorProduction).toBe("R");
        expect(api().highlight.value.spellSources).toBeNull();
    });

    it("clears a cost selection, the two rings acting as one group", async () => {
        const { wrapper, api } = render();

        await wrapper.get(".color-donut__ring--outer .color-donut__segment--G").trigger("click");
        await wrapper.get(".color-donut__ring--inner .color-donut__segment--G").trigger("click");

        expect(api().highlight.value.colorConsumption).toBeNull();
        expect(api().highlight.value.colorProduction).toBe("G");
    });

    it("counts pips in the segment tooltip and the legend", () => {
        setTestMessages({
            de: {
                pages: {
                    deck: {
                        stats: {
                            colors: {
                                production_tooltip: "Produktion {count}",
                                production_row: "{count} Pips, {percent}%"
                            }
                        }
                    }
                }
            }
        });
        const { wrapper } = render();

        expect(wrapper.get(".color-donut__ring--inner .color-donut__segment--G").attributes("data-tooltip")).toBe(
            "Produktion 34"
        );
        // 34 of 57 producing cards make green.
        expect(wrapper.findAll(".color-donut__row").map(row => row.text())).toContain("34 Pips, 60%");
    });
});

describe("DeckStatsColors — explanation", () => {
    it("compares the rings with the Mana sources section beside it", () => {
        const notes = render()
            .wrapper.findAll(".color-donut__note")
            .map(note => note.text());

        expect(notes).toEqual(["pages.deck.stats.colors.explanation", "pages.deck.stats.colors.explanation_sources"]);
    });

    it("leaves the comparison out when there is no Mana sources section", () => {
        const notes = render({ sourcesShown: false })
            .wrapper.findAll(".color-donut__note")
            .map(note => note.text());

        expect(notes).toEqual(["pages.deck.stats.colors.explanation"]);
    });
});
