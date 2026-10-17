import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import DeckStatsManaSources from "../DeckStatsManaSources.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

/** A Gruul deck whose red row is priced for a three-drop and green for a two-drop. */
const props = {
    karsten: [
        { color: "R" as const, have: 23, need: 28, short: 5, card: "Volcanic Fallout", mv: 3 },
        { color: "G" as const, have: 34.75, need: 20, short: 0, card: "Wrenn and Six", mv: 2 }
    ],
    karstenCombined: [],
    format: "commander"
};

describe("DeckStatsManaSources", () => {
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
        const wrapper = mount(DeckStatsManaSources, { props });

        const rows = wrapper.findAll(".mana-sources__row").map(li => li.text());
        expect(rows).toEqual(["23 für Volcanic Fallout (3), 28, 5", "34,75 für Wrenn and Six (2), 20"]);
    });

    it("marks only the rows the deck falls short on", () => {
        const wrapper = mount(DeckStatsManaSources, { props });

        expect(wrapper.findAll(".mana-sources__row").map(li => li.classes("mana-sources__row--short"))).toEqual([
            true,
            false
        ]);
    });

    it("lists multicolor requirements under their own heading, and only when there are any", () => {
        setTestMessages({
            de: { pages: { deck: { stats: { colors: { karsten: { combined_sufficient: "{colors} {have}" } } } } } }
        });
        const without = mount(DeckStatsManaSources, { props });
        const withCombined = mount(DeckStatsManaSources, {
            props: {
                ...props,
                karstenCombined: [
                    {
                        colors: ["R" as const, "G" as const],
                        have: 40,
                        need: 21,
                        short: 0,
                        card: "Ghor-Clan Rampager",
                        mv: 4
                    }
                ]
            }
        });

        expect(without.find(".mana-sources__combined").exists()).toBe(false);
        expect(withCombined.get(".mana-sources__subheading").text()).toBe(
            "pages.deck.stats.colors.karsten.combined_title"
        );
        expect(withCombined.findAll(".mana-sources__combined .mana-sources__symbol")).toHaveLength(2);
    });
});
