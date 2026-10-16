import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { defineComponent, h, nextTick } from "vue";
import { setTestMessages } from "@/test/i18n.ts";
import { type DeckHighlightApi, type HighlightableCard, provideDeckHighlight } from "Composables/useDeckHighlight.ts";
import SourceShareBadge from "../SourceShareBadge.vue";

const signet: HighlightableCard = {
    cmc: 2,
    type_line: "Artifact",
    produced_mana: ["W"],
    mana_cost: ["{2}"],
    mana_source: "ability"
};

const plains: HighlightableCard = {
    cmc: 0,
    type_line: "Basic Land — Plains",
    produced_mana: ["W"],
    mana_cost: [null]
};

/** Mount the badge under a highlight provider and hand back the api that drives it. */
const render = (card: HighlightableCard, variant?: "inline" | "corner") => {
    let api!: DeckHighlightApi;
    const Host = defineComponent({
        setup() {
            api = provideDeckHighlight();
            return () => h(SourceShareBadge, { card, variant });
        }
    });
    const wrapper = mount(Host);
    return { wrapper, api };
};

describe("SourceShareBadge", () => {
    it("renders nothing while no production colour is selected", () => {
        const { wrapper } = render(signet);

        expect(wrapper.find(".source-share").exists()).toBe(false);
    });

    it("shows the share of a highlighted partial source", async () => {
        const { wrapper, api } = render(signet);

        api.setColorProduction("W", 3);
        await nextTick();

        const badge = wrapper.get(".source-share");
        expect(badge.text()).toBe("¾");
        expect(badge.classes()).toContain("source-share--inline");
    });

    it("explains the share in its tooltip", async () => {
        setTestMessages({
            de: {
                pages: {
                    deck: {
                        source_share: "Zählt für {color}: {steps}.",
                        source_share_step: "{share} ab {mv}",
                        stats: { colors: { color: { W: "Weiß" } } }
                    }
                }
            }
        });
        const { wrapper, api } = render(signet);

        api.setColorProduction("W", 3);
        await nextTick();

        expect(wrapper.get(".source-share").attributes("data-tooltip")).toBe("Zählt für Weiß: ¾ ab 3.");
    });

    it("takes the image grid's corner when asked to", async () => {
        const { wrapper, api } = render(signet, "corner");

        api.setColorProduction("W", 3);
        await nextTick();

        expect(wrapper.get(".source-share").classes()).toContain("source-share--corner");
    });

    it("leaves a full source unmarked", async () => {
        const { wrapper, api } = render(plains);

        api.setColorProduction("W", 3);
        await nextTick();

        expect(wrapper.find(".source-share").exists()).toBe(false);
    });

    it("mutes a source that only counts toward bigger spells, and says so", async () => {
        setTestMessages({
            de: {
                pages: {
                    deck: {
                        source_share: "Zählt für {color}: {steps}.",
                        source_share_step: "{share} ab {mv}",
                        source_share_later: "Noch nicht für {mv}.",
                        stats: { colors: { color: { W: "Weiß" } } }
                    }
                }
            }
        });
        const { wrapper, api } = render(signet);

        api.setColorProduction("W", 2);
        await nextTick();

        const badge = wrapper.get(".source-share");
        expect(badge.text()).toBe("¾");
        expect(badge.classes()).toContain("source-share--later");
        expect(badge.attributes("data-tooltip")).toBe("Zählt für Weiß: ¾ ab 3.<br />Noch nicht für 2.");
    });

    it("shows a later source at the most it ever counts as", async () => {
        const forest: HighlightableCard = {
            cmc: 0,
            type_line: "Basic Land — Forest",
            produced_mana: ["G"],
            mana_cost: [null],
            is_basic_land: true
        };
        const cultivate: HighlightableCard = {
            cmc: 3,
            type_line: "Sorcery",
            produced_mana: null,
            mana_cost: ["{2}{G}"],
            land_search_pattern: "basic"
        };
        let api!: DeckHighlightApi;
        const Host = defineComponent({
            setup() {
                api = provideDeckHighlight(() => [forest, cultivate]);
                return () => h(SourceShareBadge, { card: cultivate });
            }
        });
        const wrapper = mount(Host);

        api.setColorProduction("G", 3);
        await nextTick();

        expect(wrapper.get(".source-share").text()).toBe("½");
    });
});
