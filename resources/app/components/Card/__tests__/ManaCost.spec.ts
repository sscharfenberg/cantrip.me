import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import ManaCost from "../ManaCost.vue";

type ManaCostProp = string | null | (string | null)[];

/** `src` of every symbol image, in render order. */
const symbolsOf = (manaCost: ManaCostProp): string[] =>
    mount(ManaCost, { props: { manaCost } })
        .findAll("img")
        .map(img => img.attributes("src") ?? "");

describe("ManaCost", () => {
    it("renders one image per symbol, in cost order", () => {
        expect(symbolsOf("{2}{W}{U}")).toEqual(["/symbol/2.svg", "/symbol/W.svg", "/symbol/U.svg"]);
    });

    it("labels each symbol with the token it stands for", () => {
        const alts = mount(ManaCost, { props: { manaCost: "{2}{W}" } })
            .findAll("img")
            .map(img => img.attributes("alt"));

        expect(alts).toEqual(["{2}", "{W}"]);
    });

    it("rewrites the slash in a hybrid token, matching the sprite filenames", () => {
        expect(symbolsOf("{W/U}")).toEqual(["/symbol/W-U.svg"]);
        expect(symbolsOf("{2/W}")).toEqual(["/symbol/2-W.svg"]);
        expect(symbolsOf("{W/P}")).toEqual(["/symbol/W-P.svg"]);
    });

    it("renders nothing at all for a card with no cost", () => {
        expect(
            mount(ManaCost, { props: { manaCost: null } })
                .find("span")
                .exists()
        ).toBe(false);
        expect(
            mount(ManaCost, { props: { manaCost: "" } })
                .find("span")
                .exists()
        ).toBe(false);
        expect(
            mount(ManaCost, { props: { manaCost: [] } })
                .find("span")
                .exists()
        ).toBe(false);
    });

    it("joins the faces of a split card with a separator", () => {
        const wrapper = mount(ManaCost, { props: { manaCost: ["{1}{R}", "{1}{U}"] } });

        expect(wrapper.findAll(".mana-cost__separator")).toHaveLength(1);
        expect(wrapper.findAll("img").map(img => img.attributes("src"))).toEqual([
            "/symbol/1.svg",
            "/symbol/R.svg",
            "/symbol/1.svg",
            "/symbol/U.svg"
        ]);
    });

    it("puts no separator before the first face", () => {
        expect(
            mount(ManaCost, { props: { manaCost: ["{R}"] } })
                .find(".mana-cost__separator")
                .exists()
        ).toBe(false);
    });

    it("drops a costless face rather than leaving a stray separator", () => {
        // The back of an MDFC land has no cost at all.
        const wrapper = mount(ManaCost, { props: { manaCost: ["{1}{G}", null] } });

        expect(wrapper.findAll(".mana-cost__separator")).toHaveLength(0);
        expect(wrapper.findAll("img")).toHaveLength(2);
    });

    it("accepts a single-element array the same as a bare string", () => {
        expect(symbolsOf(["{W}"])).toEqual(symbolsOf("{W}"));
    });

    it("ignores text outside the braces", () => {
        expect(symbolsOf("{W} // {U}")).toEqual(["/symbol/W.svg", "/symbol/U.svg"]);
    });
});
