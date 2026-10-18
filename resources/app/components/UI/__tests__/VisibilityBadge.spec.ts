import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { routerMock } from "@/test/inertia.ts";
import VisibilityBadge from "../VisibilityBadge.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

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

describe("VisibilityBadge — changing the visibility", () => {
    const picker = (visibility: string) =>
        mount(VisibilityBadge, { props: { visibility, endpoint: "/decks/d1/visibility" } });
    const entries = (wrapper: ReturnType<typeof picker>) => wrapper.findAll(".popover-list-item");

    it("is a plain badge without an endpoint", () => {
        expect(
            mount(VisibilityBadge, { props: { visibility: "public" } })
                .find("button")
                .exists()
        ).toBe(false);
    });

    it("opens a menu with both visibilities, each with its icon", () => {
        const wrapper = picker("private");

        expect(wrapper.find("button.visibility-badge").attributes("popovertarget")).toBe(
            wrapper.find("[popover]").attributes("id")
        );
        expect(entries(wrapper).map(entry => entry.text())).toEqual([
            "enums.visibility.private",
            "enums.visibility.public"
        ]);
        expect(entries(wrapper).map(entry => entry.find("use").attributes("href"))).toEqual([
            "#visibility-off",
            "#visibility-on"
        ]);
    });

    it("checks the current visibility and does not offer it", () => {
        const [current, other] = entries(picker("public")).reverse();

        expect(current.attributes("disabled")).toBeDefined();
        expect(current.attributes("aria-current")).toBe("true");
        expect(current.findAll("use").map(use => use.attributes("href"))).toEqual(["#visibility-on", "#check"]);
        expect(other.attributes("disabled")).toBeUndefined();
        expect(other.find('use[href="#check"]').exists()).toBe(false);
    });

    it("sets the other visibility at the endpoint", async () => {
        await entries(picker("private"))[1].trigger("click");

        expect(routerMock.patch).toHaveBeenCalledWith(
            "/decks/d1/visibility",
            { visibility: "public" },
            expect.objectContaining({ preserveScroll: true })
        );
    });
});
