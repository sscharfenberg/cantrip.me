import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import type { CardLegality } from "Types/cardPreview.ts";
import CardLegalities from "../CardLegalities.vue";

const legality = (format: string, legality: string): CardLegality => ({ format, legality }) as CardLegality;

/** The sprite id shown for a given legality value. */
const iconFor = (value: string): string | undefined => {
    const wrapper = mount(CardLegalities, { props: { legalities: [legality("modern", value)] } });
    return wrapper.find("use").attributes("href");
};

describe("CardLegalities — status icons", () => {
    it.each([
        ["legal", "#check"],
        ["not_legal", "#close"],
        ["restricted", "#warning"],
        ["banned", "#error"]
    ])("shows %s as %s", (value, icon) => {
        expect(iconFor(value)).toBe(icon);
    });

    it("falls back to the legal icon for a value it does not know", () => {
        // New Scryfall legality values must not leave the cell empty.
        expect(iconFor("future_thing")).toBe("#check");
    });

    it("carries the status as a class, with the underscore hyphenated for CSS", () => {
        const wrapper = mount(CardLegalities, { props: { legalities: [legality("modern", "not_legal")] } });

        expect(wrapper.find(".legalities__status").classes()).toContain("legalities__status--not-legal");
    });

    it("names the status in a tooltip", () => {
        // Passed in FloatingVue's object form so the tooltip can escape the
        // preview modal's stacking context.
        const wrapper = mount(CardLegalities, { props: { legalities: [legality("modern", "banned")] } });

        expect(wrapper.find(".legalities__status").attributes("data-tooltip")).toBe("enums.card_legalities.banned");
    });
});

describe("CardLegalities — ordering", () => {
    it("sorts by the translated format name, not by the raw slug", () => {
        // The slugs would sort standard/commander/modern; the German labels
        // sort the other way, and that is what the user reads.
        setTestMessages({
            de: {
                enums: {
                    card_formats: { standard: "Alpha", commander: "Beta", modern: "Gamma" },
                    card_legalities: { legal: "Erlaubt" }
                },
                form: { fields: { legalities: "Formate" } }
            }
        });
        const wrapper = mount(CardLegalities, {
            props: {
                legalities: [legality("modern", "legal"), legality("standard", "legal"), legality("commander", "legal")]
            }
        });

        expect(wrapper.findAll(".legalities__format").map(node => node.text())).toEqual(["Alpha", "Beta", "Gamma"]);
    });

    it("does not reorder the array it was handed", () => {
        const legalities = [legality("modern", "legal"), legality("commander", "legal")];

        mount(CardLegalities, { props: { legalities } });

        expect(legalities.map(entry => entry.format)).toEqual(["modern", "commander"]);
    });

    it("renders one row per format", () => {
        const wrapper = mount(CardLegalities, {
            props: { legalities: [legality("modern", "legal"), legality("commander", "banned")] }
        });

        expect(wrapper.findAll(".legalities__item")).toHaveLength(2);
    });

    it("renders an empty list rather than failing when a card has no legalities", () => {
        const wrapper = mount(CardLegalities, { props: { legalities: [] } });

        expect(wrapper.findAll(".legalities__item")).toHaveLength(0);
        expect(wrapper.find(".card-legalities").exists()).toBe(true);
    });
});
