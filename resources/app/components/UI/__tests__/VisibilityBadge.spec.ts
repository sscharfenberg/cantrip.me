import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import VisibilityBadge from "../VisibilityBadge.vue";

describe("VisibilityBadge", () => {
    it("shows a closed eye for a private deck", () => {
        const wrapper = mount(VisibilityBadge, { props: { visibility: "private" } });

        expect(wrapper.find("use").attributes("href")).toBe("#visibility-off");
        expect(wrapper.classes()).toContain("visibility-badge--private");
    });

    it("shows an open eye for a public deck", () => {
        const wrapper = mount(VisibilityBadge, { props: { visibility: "public" } });

        expect(wrapper.find("use").attributes("href")).toBe("#visibility-on");
        expect(wrapper.classes()).toContain("visibility-badge--public");
    });

    it("names both the field and its value in the tooltip", () => {
        const wrapper = mount(VisibilityBadge, { props: { visibility: "private" } });

        expect(wrapper.attributes("data-tooltip")).toBe("pages.deck.visibility: enums.visibility.private");
    });

    it("still renders a badge for a value it does not know", () => {
        // The value arrives as a plain string from the server enum. The icon
        // falls back to the open eye; the class is passed through verbatim, so
        // an unknown value renders unstyled rather than mislabelled as public.
        const wrapper = mount(VisibilityBadge, { props: { visibility: "unlisted" } });

        expect(wrapper.find("use").attributes("href")).toBe("#visibility-on");
        expect(wrapper.classes()).toContain("visibility-badge--unlisted");
    });
});
