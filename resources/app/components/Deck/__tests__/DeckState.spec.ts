import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import DeckState from "../DeckState.vue";

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
