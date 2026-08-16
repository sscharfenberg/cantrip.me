import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import DeckState from "../DeckState.vue";

const render = (state: string) => mount(DeckState, { props: { state } });

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
