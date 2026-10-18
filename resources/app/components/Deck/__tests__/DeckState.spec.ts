import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { routerMock } from "@/test/inertia.ts";
import DeckState from "../DeckState.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const render = (state: string, explain = false) => mount(DeckState, { props: { state, explain } });

describe("DeckState", () => {
    it.each([
        ["planned", "info", "#planned"],
        ["built", "success", "#finished"],
        ["archived", "warning", "#archived"]
    ])("renders %s as a %s badge with the %s icon", (state, badgeType, icon) => {
        const wrapper = render(state);

        expect(wrapper.classes()).toContain(badgeType);
        expect(wrapper.find("use").attributes("href")).toBe(icon);
    });

    it("maps built to the finished icon, which is the one that exists in the sprite", () => {
        // The only state whose icon name differs from the state name.
        expect(render("built").find("use").attributes("href")).toBe("#finished");
    });

    it("names the state in the label", () => {
        expect(render("built").text()).toBe("enums.deck_state.built");
    });

    it("says what the badge means in its tooltip", () => {
        expect(render("built").attributes("data-tooltip")).toBe("pages.deck.state_is enums.deck_state.built");
    });

    it("falls back to the neutral variant for a state it does not know", () => {
        // New `App\Enums\DeckState` values must still render a badge.
        const wrapper = render("mothballed");

        expect(wrapper.classes()).toContain("info");
        expect(wrapper.find("use").attributes("href")).toBe("#mothballed");
    });
});

describe("DeckState — badge legend", () => {
    it("says only what the state is when the legend is off", () => {
        // Non-owners, and owners with the collection master switch off,
        // see no per-card badges — a legend would describe nothing.
        const tooltip = render("planned").attributes("data-tooltip");

        expect(tooltip).toBe("pages.deck.state_is enums.deck_state.planned");
    });

    it("explains the availability icons on a planned deck", () => {
        const tooltip = render("planned", true).attributes("data-tooltip") ?? "";

        expect(tooltip).toContain("pages.deck.state_tooltip.planned.intro");
        expect(tooltip).toContain("pages.deck.state_tooltip.planned.available");
        expect(tooltip).toContain("pages.deck.state_tooltip.planned.partial");
        expect(tooltip).toContain("pages.deck.state_tooltip.planned.unavailable");
        expect(tooltip).toContain("pages.deck.state_tooltip.planned.note");
        expect(tooltip.split("<br />")).toHaveLength(6);
    });

    it.each(["built", "archived"])("points %s at the tracking-mode badges instead", state => {
        const tooltip = render(state, true).attributes("data-tooltip") ?? "";

        expect(tooltip).toContain("pages.deck.state_tooltip.tracked");
        expect(tooltip).not.toContain("pages.deck.state_tooltip.planned");
    });

    it("keeps the state line first whatever the legend says", () => {
        const tooltip = render("planned", true).attributes("data-tooltip") ?? "";

        expect(tooltip.split("<br />")[0]).toBe("pages.deck.state_is enums.deck_state.planned");
    });
});

describe("DeckState — changing the state", () => {
    const picker = (state: string) => mount(DeckState, { props: { state, deckId: "d1" } });
    const entries = (wrapper: ReturnType<typeof picker>) => wrapper.findAll(".popover-list-item");

    it("is a plain badge without a deck to change", () => {
        const wrapper = render("built");

        expect(wrapper.find("button").exists()).toBe(false);
        expect(wrapper.find("[popover]").exists()).toBe(false);
    });

    it("opens a menu of every state, each with its icon", () => {
        const wrapper = picker("built");

        expect(wrapper.find("button.deck-state").attributes("popovertarget")).toBe(
            wrapper.find("[popover]").attributes("id")
        );
        expect(entries(wrapper).map(entry => entry.text())).toEqual([
            "enums.deck_state.planned",
            "enums.deck_state.built",
            "enums.deck_state.archived"
        ]);
        expect(entries(wrapper).map(entry => entry.find("use").attributes("href"))).toEqual([
            "#planned",
            "#finished",
            "#archived"
        ]);
    });

    it("checks the current state and does not offer it", () => {
        const current = entries(picker("built"))[1];

        expect(current.attributes("disabled")).toBeDefined();
        expect(current.attributes("aria-current")).toBe("true");
        expect(current.classes()).toContain("popover-list-item--selected");
        expect(current.findAll("use").map(use => use.attributes("href"))).toEqual(["#finished", "#check"]);
    });

    it("marks only the current state", () => {
        const wrapper = picker("planned");

        expect(entries(wrapper).map(entry => entry.attributes("disabled") !== undefined)).toEqual([true, false, false]);
        expect(wrapper.findAll('use[href="#check"]')).toHaveLength(1);
    });

    it("sets another state", async () => {
        await entries(picker("built"))[2].trigger("click");

        expect(routerMock.patch).toHaveBeenCalledWith(
            "/decks/d1/state",
            { state: "archived" },
            expect.objectContaining({ preserveScroll: true })
        );
    });
});
