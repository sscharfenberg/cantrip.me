import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { defineComponent, h } from "vue";
import { setTestMessages } from "@/test/i18n.ts";
import { type DeckHighlightApi, provideDeckHighlight } from "Composables/useDeckHighlight.ts";
import DeckStatsColors from "../DeckStatsColors.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const NO_PIPS = { W: 0, U: 0, B: 0, R: 0, G: 0 };

/** A Gruul deck whose red row is priced for a three-drop and green for a two-drop. */
const props = {
    cost: { ...NO_PIPS, R: 20, G: 30 },
    production: { ...NO_PIPS, R: 23, G: 34 },
    productionBasis: { R: { card: "Volcanic Fallout", mv: 3 }, G: { card: "Wrenn and Six", mv: 2 } },
    karsten: [
        { color: "R" as const, have: 23, need: 28, short: 5, card: "Volcanic Fallout", mv: 3 },
        { color: "G" as const, have: 34, need: 20, short: 0, card: "Wrenn and Six", mv: 2 }
    ],
    karstenCombined: [],
    format: "commander"
};

/** Mount the panel under a highlight provider and hand back the api it drives. */
const render = () => {
    let api!: DeckHighlightApi;
    const Host = defineComponent({
        setup() {
            api = provideDeckHighlight();
            return () => h(DeckStatsColors, props);
        }
    });
    return { wrapper: mount(Host), api: () => api };
};

describe("DeckStatsColors — production ring", () => {
    it("selects a production colour at the mana value its count is priced for", async () => {
        const { wrapper, api } = render();

        await wrapper.get(".color-donut__ring--inner .color-donut__segment--R").trigger("click");

        expect(api().highlight.value.colorProduction).toBe("R");
        expect(api().highlight.value.colorProductionMv).toBe(3);
    });

    it("names the spell in the segment tooltip", () => {
        setTestMessages({
            de: { pages: { deck: { stats: { colors: { production_tooltip: "{count} {symbol} für {card} ({mv})" } } } } }
        });
        const { wrapper } = render();

        expect(wrapper.get(".color-donut__ring--inner .color-donut__segment--G").attributes("data-tooltip")).toContain(
            "für Wrenn and Six (2)"
        );
    });
});

describe("DeckStatsColors — Karsten rows", () => {
    it("names the spell each row is priced for", () => {
        setTestMessages({
            de: {
                pages: {
                    deck: {
                        stats: {
                            colors: {
                                karsten: {
                                    sufficient: "{have} für {card} ({mv}), {need}",
                                    short: "{have} für {card} ({mv}), {need}, {short}"
                                }
                            }
                        }
                    }
                }
            }
        });
        const { wrapper } = render();

        const rows = wrapper.findAll(".color-donut__section .color-donut__rows li").map(li => li.text());
        expect(rows).toContain("23 für Volcanic Fallout (3), 28, 5");
        expect(rows).toContain("34 für Wrenn and Six (2), 20");
    });
});
