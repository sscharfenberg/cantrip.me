import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import Headline from "../Headline.vue";

describe("Headline", () => {
    it("renders an h2 by default", () => {
        const wrapper = mount(Headline, { slots: { default: "Mono-White Aggro" } });

        expect(wrapper.find("h2").exists()).toBe(true);
        expect(wrapper.text()).toBe("Mono-White Aggro");
    });

    it.each([
        [2, "h2"],
        [3, "h3"],
        [4, "h4"]
    ] satisfies [2 | 3 | 4, string][])("renders size %i as %s", (size, tag) => {
        const wrapper = mount(Headline, { props: { size }, slots: { default: "Lands" } });

        expect(wrapper.find(tag).exists()).toBe(true);
    });

    it("renders exactly one heading, not one per supported size", () => {
        const wrapper = mount(Headline, { props: { size: 3 } });

        expect(wrapper.findAll("h2, h3, h4")).toHaveLength(1);
    });

    it("sets the anchor id so a sticky nav can jump to it", () => {
        const wrapper = mount(Headline, { props: { size: 3, anchorId: "deck-stats" } });

        expect(wrapper.find("h3").attributes("id")).toBe("deck-stats");
    });

    it("omits the right-hand block when nothing fills it", () => {
        expect(mount(Headline).find(".right").exists()).toBe(false);
    });

    it("renders the right-hand block when a slot supplies one", () => {
        const wrapper = mount(Headline, { slots: { default: "Lands", right: "24" } });

        expect(wrapper.find(".right").text()).toBe("24");
    });
});
