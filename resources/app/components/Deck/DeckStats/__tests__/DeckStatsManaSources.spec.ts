import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import type {
    KarstenColorAnalysis,
    KarstenCombinedAnalysis,
    KarstenCommandZoneCheck
} from "Composables/useDeckStats.ts";
import DeckStatsManaSources from "../DeckStatsManaSources.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

/** A per-color row; the pick fields default to "automatic, nothing else to pick". */
const colorRow = (row: Omit<KarstenColorAnalysis, "key" | "picked" | "automatic" | "candidates">) => ({
    ...row,
    key: row.color,
    picked: false,
    automatic: { card: row.card, mv: row.mv },
    candidates: []
});

/** A Gruul deck whose red row is priced for a three-drop and green for a two-drop. */
const props = {
    karsten: [
        colorRow({ color: "R", have: 23, need: 28, short: 5, card: "Volcanic Fallout", mv: 3 }),
        colorRow({ color: "G", have: 34.75, need: 20, short: 0, card: "Wrenn and Six", mv: 2 })
    ] satisfies KarstenColorAnalysis[],
    karstenCombined: [] as KarstenCombinedAnalysis[],
    commandZone: [] as KarstenCommandZoneCheck[],
    format: "commander",
    automaticSpell: "hardest" as const
};

const combined: KarstenCombinedAnalysis = {
    colors: ["R", "G"],
    have: 40,
    need: 21,
    short: 0,
    card: "Ghor-Clan Rampager",
    mv: 4,
    key: "RG",
    picked: false,
    automatic: { card: "Ghor-Clan Rampager", mv: 4 },
    candidates: []
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
                                    sufficient: "{have} für {spell}, {need}",
                                    short: "{have} für {spell}, {need}, {short}",
                                    spell: "{card} ({mv})"
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

    it("heads the single-color rows to match the multicolor ones", () => {
        const wrapper = mount(DeckStatsManaSources, { props: { ...props, karstenCombined: [combined] } });

        expect(wrapper.findAll(".mana-sources__subheading").map(h => h.text())).toEqual([
            "pages.deck.stats.colors.karsten.automatic.label",
            "pages.deck.stats.colors.karsten.single_title",
            "pages.deck.stats.colors.karsten.combined_title"
        ]);
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
        const withCombined = mount(DeckStatsManaSources, { props: { ...props, karstenCombined: [combined] } });

        expect(without.find(".mana-sources__group--combined").exists()).toBe(false);
        expect(withCombined.findAll(".mana-sources__group--combined .mana-sources__symbol")).toHaveLength(2);
    });

    it("asks for the spell picker of the row whose spell is clicked", async () => {
        setTestMessages({
            de: {
                pages: {
                    deck: { stats: { colors: { karsten: { combined_sufficient: "{spell}", short: "{spell}" } } } }
                }
            }
        });
        const wrapper = mount(DeckStatsManaSources, { props: { ...props, karstenCombined: [combined] } });

        await wrapper.get(".mana-sources__group--single .spell-link").trigger("click");
        await wrapper.get(".mana-sources__group--combined .spell-link").trigger("click");

        expect(wrapper.emitted("pick")).toEqual([["R"], ["RG"]]);
    });

    it("marks a picked spell's chip, and only it", () => {
        setTestMessages({
            de: { pages: { deck: { stats: { colors: { karsten: { short: "{spell}", sufficient: "{spell}" } } } } } }
        });
        const picked = { ...props.karsten[1], picked: true, card: "Eight-and-a-Half-Tails" };
        const wrapper = mount(DeckStatsManaSources, { props: { ...props, karsten: [props.karsten[0], picked] } });

        expect(wrapper.findAll(".spell-link").map(chip => chip.classes("spell-link--picked"))).toEqual([false, true]);
    });

    it("offers the reset link only while a row shows a picked spell", async () => {
        const picked = { ...props.karsten[1], picked: true };
        const without = mount(DeckStatsManaSources, { props });
        const withPick = mount(DeckStatsManaSources, { props: { ...props, karsten: [props.karsten[0], picked] } });

        expect(without.find(".mana-sources__reset").exists()).toBe(false);
        await withPick.get(".mana-sources__reset").trigger("click");

        expect(withPick.emitted("reset-picks")).toEqual([[]]);
    });

    it("counts a multicolor row's pick toward the reset link too", () => {
        const wrapper = mount(DeckStatsManaSources, {
            props: { ...props, karstenCombined: [{ ...combined, picked: true }] }
        });

        expect(wrapper.find(".mana-sources__reset").exists()).toBe(true);
    });

    it("offers hardest, easiest and cheapest as the automatic spell, and reports a change", () => {
        const wrapper = mount(DeckStatsManaSources, { props: { ...props, automaticSpell: "easiest" } });
        const select = wrapper.getComponent(MonoSelect);

        expect(select.props("options").map((option: { value: string }) => option.value)).toEqual([
            "hardest",
            "easiest",
            "cheapest"
        ]);
        expect(select.props("selected")).toBe("easiest");
        select.vm.$emit("change", "hardest");

        expect(wrapper.emitted("automatic-spell")).toEqual([["hardest"]]);
    });

    it("stacks the choice: its headline, the dropdown, then why a row needs a spell", () => {
        const wrapper = mount(DeckStatsManaSources, { props });
        const block = wrapper.get(".mana-sources__automatic");

        expect([...block.element.children].map(child => child.className.split(" ")[0])).toEqual([
            "mana-sources__subheading",
            "form-select",
            "info-box"
        ]);
        expect(block.find(".form-group").exists()).toBe(false);
        expect(block.findAll(".info-box p").map(p => p.text())).toEqual([
            "pages.deck.stats.colors.karsten.automatic.hint",
            "pages.deck.stats.colors.karsten.automatic.hint_one_drops"
        ]);
    });
});

describe("DeckStatsManaSources — the command zone", () => {
    /** Nekusar in a deck with too few red sources: three colors met, red short. */
    const nekusar: KarstenCommandZoneCheck = {
        oracleId: "nekusar",
        card: "Nekusar, the Mindrazer",
        mv: 5,
        requirements: [
            { key: "U", colors: ["U"], have: 20, need: 16, short: 0 },
            { key: "B", colors: ["B"], have: 20.75, need: 16, short: 0 },
            { key: "R", colors: ["R"], have: 5, need: 16, short: 11 },
            { key: "UBR", colors: ["U", "B", "R"], have: 45, need: 31, short: 0 }
        ],
        onCurve: false
    };
    const thalia: KarstenCommandZoneCheck = {
        oracleId: "thalia",
        card: "Thalia",
        mv: 2,
        requirements: [{ key: "W", colors: ["W"], have: 20, need: 19, short: 0 }],
        onCurve: true
    };

    it("only shows up for a deck with a command zone to check, ahead of the automatic spell", () => {
        const without = mount(DeckStatsManaSources, { props });
        const withCheck = mount(DeckStatsManaSources, { props: { ...props, commandZone: [thalia] } });

        expect(without.find(".mana-sources__group--command-zone").exists()).toBe(false);
        expect(withCheck.findAll(".mana-sources__subheading").map(h => h.text())).toEqual([
            "pages.deck.stats.colors.karsten.command_zone.title",
            "pages.deck.stats.colors.karsten.automatic.label",
            "pages.deck.stats.colors.karsten.single_title"
        ]);
    });

    it("says per card whether it can be cast on curve, bold only when it cannot", () => {
        setTestMessages({
            de: {
                pages: {
                    deck: {
                        stats: {
                            colors: {
                                karsten: {
                                    spell: "{card} ({mv})",
                                    command_zone: { on_curve: "{spell}: ja", short: "{spell}: nein" }
                                }
                            }
                        }
                    }
                }
            }
        });
        const wrapper = mount(DeckStatsManaSources, { props: { ...props, commandZone: [nekusar, thalia] } });
        const verdicts = wrapper.findAll(".mana-sources__verdict");

        expect(verdicts.map(v => v.text())).toEqual(["Nekusar, the Mindrazer (5): nein", "Thalia (2): ja"]);
        expect(verdicts.map(v => v.classes("mana-sources__verdict--short"))).toEqual([true, false]);
    });

    it("lists every requirement with its sources, bold only where it falls short", () => {
        setTestMessages({
            de: {
                pages: {
                    deck: { stats: { colors: { karsten: { command_zone: { requirement: "{colors}{have}/{need}" } } } } }
                }
            }
        });
        const wrapper = mount(DeckStatsManaSources, { props: { ...props, commandZone: [nekusar] } });
        const requirements = wrapper.findAll(".mana-sources__requirement");

        expect(requirements.map(r => [r.attributes("data-key"), r.text()])).toEqual([
            ["U", "20/16"],
            ["B", "20,75/16"],
            ["R", "5/16"],
            ["UBR", "45/31"]
        ]);
        expect(requirements.map(r => r.classes("mana-sources__requirement--short"))).toEqual([
            false,
            false,
            true,
            false
        ]);
        expect(requirements[3].findAll(".mana-sources__symbol").map(img => img.attributes("alt"))).toEqual([
            "pages.deck.stats.colors.color.U",
            "pages.deck.stats.colors.color.B",
            "pages.deck.stats.colors.color.R"
        ]);
    });

    it("explains a multicolored card's several requirements only when one is in the command zone", () => {
        const note = "pages.deck.stats.colors.karsten.command_zone.multicolor";
        const notes = (commandZone: KarstenCommandZoneCheck[]) =>
            mount(DeckStatsManaSources, { props: { ...props, commandZone } })
                .findAll(".mana-sources__group--command-zone .info-box p")
                .map(p => p.text());

        expect(notes([thalia])).toEqual([]);
        expect(notes([thalia, nekusar])).toEqual([note]);
    });
});
