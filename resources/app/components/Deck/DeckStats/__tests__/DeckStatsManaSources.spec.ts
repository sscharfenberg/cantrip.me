import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import type { AutomaticSpell, KarstenColorAnalysis, KarstenCombinedAnalysis } from "Composables/useDeckStats.ts";
import DeckStatsManaSources from "../DeckStatsManaSources.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

/** A per-color row; the pick fields default to "automatic, nothing else to pick". */
const colorRow = (row: Omit<KarstenColorAnalysis, "key" | "picked" | "automatic" | "candidates">) => ({
    ...row,
    key: row.color,
    picked: false,
    automatic: { card: row.card, mv: row.mv, chosenBy: "hardest" as const },
    candidates: []
});

/** A Gruul deck whose red row is priced for a three-drop and green for a two-drop. */
const props = {
    karsten: [
        colorRow({ color: "R", have: 23, need: 28, short: 5, card: "Volcanic Fallout", mv: 3 }),
        colorRow({ color: "G", have: 34.75, need: 20, short: 0, card: "Wrenn and Six", mv: 2 })
    ] satisfies KarstenColorAnalysis[],
    karstenCombined: [] as KarstenCombinedAnalysis[],
    format: "commander",
    automaticSpell: "hardest" as AutomaticSpell,
    commanders: [] as { oracle_card_id: string; name: string; is_partner: boolean }[]
};

/** A lone commander, in a Commander deck. */
const kenrith = { oracle_card_id: "kenrith", name: "Kenrith, the Returned King", is_partner: false };

const combined: KarstenCombinedAnalysis = {
    colors: ["R", "G"],
    have: 40,
    need: 21,
    short: 0,
    card: "Ghor-Clan Rampager",
    mv: 4,
    key: "RG",
    picked: false,
    automatic: { card: "Ghor-Clan Rampager", mv: 4, chosenBy: "hardest" },
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

    it("offers the commander after the rules, as one plain entry for one commander", () => {
        const wrapper = mount(DeckStatsManaSources, {
            props: { ...props, commanders: [kenrith] }
        });

        expect(wrapper.getComponent(MonoSelect).props("options")).toEqual([
            { value: "hardest", label: "pages.deck.stats.colors.karsten.automatic.hardest" },
            { value: "easiest", label: "pages.deck.stats.colors.karsten.automatic.easiest" },
            { value: "cheapest", label: "pages.deck.stats.colors.karsten.automatic.cheapest" },
            { value: "commander", label: "pages.deck.stats.colors.karsten.automatic.commander" }
        ]);
    });

    it("offers each of several commanders by name, and reports which one is chosen", () => {
        setTestMessages({
            de: {
                pages: {
                    deck: { stats: { colors: { karsten: { automatic: { commander_named: "Commander: {name}" } } } } }
                }
            }
        });
        const wrapper = mount(DeckStatsManaSources, {
            props: {
                ...props,
                automaticSpell: "commander:tymna",
                commanders: [
                    { oracle_card_id: "kraum", name: "Kraum, Ludevic's Opus", is_partner: false },
                    { oracle_card_id: "tymna", name: "Tymna the Weaver", is_partner: true }
                ]
            }
        });
        const select = wrapper.getComponent(MonoSelect);

        expect(select.props("options").slice(3)).toEqual([
            { value: "commander:kraum", label: "Commander: Kraum, Ludevic's Opus" },
            { value: "commander:tymna", label: "Commander: Tymna the Weaver" }
        ]);
        expect(select.props("selected")).toBe("commander:tymna");
        select.vm.$emit("change", "commander:kraum");

        expect(wrapper.emitted("automatic-spell")).toEqual([["commander:kraum"]]);
    });

    it("explains the fallback to the hardest spell only while a commander is chosen", () => {
        const hint = "pages.deck.stats.colors.karsten.automatic.hint_commander";
        const commanders = [kenrith];
        const byRule = mount(DeckStatsManaSources, { props: { ...props, commanders } });
        const byCommander = mount(DeckStatsManaSources, {
            props: { ...props, commanders, automaticSpell: "commander" }
        });

        expect(byRule.findAll(".mana-sources__hint p").map(p => p.text())).not.toContain(hint);
        expect(byCommander.findAll(".mana-sources__hint p").map(p => p.text())).toContain(hint);
    });

    describe("in Oathbreaker", () => {
        const oathbreaker = { oracle_card_id: "narset", name: "Narset, Parter of Veils", is_partner: false };
        const signatureSpell = { oracle_card_id: "brainstorm", name: "Brainstorm", is_partner: true };
        const oathbreakerProps = { ...props, format: "oathbreaker", commanders: [oathbreaker, signatureSpell] };

        it("offers the oathbreaker and the signature spell by name", () => {
            setTestMessages({
                de: {
                    pages: {
                        deck: {
                            stats: {
                                colors: {
                                    karsten: {
                                        automatic: {
                                            oathbreaker_named: "Oathbreaker: {name}",
                                            signature_spell_named: "Signaturzauber: {name}"
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            });
            const wrapper = mount(DeckStatsManaSources, { props: oathbreakerProps });

            expect(wrapper.getComponent(MonoSelect).props("options").slice(3)).toEqual([
                { value: "commander:narset", label: "Oathbreaker: Narset, Parter of Veils" },
                { value: "commander:brainstorm", label: "Signaturzauber: Brainstorm" }
            ]);
        });

        it("offers a lone oathbreaker as one plain entry", () => {
            const wrapper = mount(DeckStatsManaSources, { props: { ...oathbreakerProps, commanders: [oathbreaker] } });

            expect(wrapper.getComponent(MonoSelect).props("options").slice(3)).toEqual([
                { value: "commander", label: "pages.deck.stats.colors.karsten.automatic.oathbreaker" }
            ]);
        });

        it("explains the fallback for the slot the rows follow", () => {
            const hints = (automaticSpell: AutomaticSpell) =>
                mount(DeckStatsManaSources, { props: { ...oathbreakerProps, automaticSpell } })
                    .findAll(".mana-sources__hint p")
                    .map(p => p.text())
                    .slice(2);

            expect(hints("commander:narset")).toEqual(["pages.deck.stats.colors.karsten.automatic.hint_oathbreaker"]);
            expect(hints("commander:brainstorm")).toEqual([
                "pages.deck.stats.colors.karsten.automatic.hint_signature_spell"
            ]);
            expect(hints("hardest")).toEqual([]);
        });
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
