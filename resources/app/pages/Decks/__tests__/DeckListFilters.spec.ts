import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import { type DeckFilters, type FilterableDeck, emptyDeckFilters } from "@/utils/deckListFilters.ts";
import DeckListFilters from "../DeckListFilters.vue";

const deck = (state: string, bracket: number | null, visibility: string): FilterableDeck => ({
    state,
    bracket,
    visibility
});

/** Every group has two values to choose between. */
const mixed = [deck("built", 3, "public"), deck("planned", 2, "private"), deck("built", null, "private")];

/**
 * Mount with a working v-model: an emitted update is written back into the
 * prop, as a parent's `v-model` would.
 */
const render = (decks: FilterableDeck[] = mixed, modelValue: DeckFilters = emptyDeckFilters()) => {
    const wrapper = mount(DeckListFilters, {
        props: {
            decks,
            modelValue,
            "onUpdate:modelValue": (value: DeckFilters) => wrapper.setProps({ modelValue: value })
        }
    });
    return wrapper;
};

type Wrapper = ReturnType<typeof render>;

/** The group a screen reader announces under this label (key echo). */
const group = (wrapper: Wrapper, label: string) => wrapper.get(`.deck-filters__group[aria-label="${label}"]`);

const groupLabels = (wrapper: Wrapper): string[] =>
    wrapper.findAll(".deck-filters__group").map(g => g.attributes("aria-label")!);

const badge = (wrapper: Wrapper, label: string, text: string) =>
    group(wrapper, label)
        .findAll("button")
        .find(button => button.text() === text)!;

/** The most recent v-model update, as emitted arguments. */
const lastUpdate = (wrapper: Wrapper): unknown[] => {
    const updates = wrapper.emitted("update:modelValue")!;
    return updates[updates.length - 1];
};

const pressed = (wrapper: Wrapper): string[] =>
    wrapper.findAll('button[aria-pressed="true"]').map(button => button.text());

describe("DeckListFilters — which groups show", () => {
    it("offers state, bracket and visibility when each can narrow the folder", () => {
        expect(groupLabels(render())).toEqual([
            "pages.decks.filters.state",
            "form.fields.deck_bracket",
            "pages.deck.visibility"
        ]);
    });

    it("leaves out a group with nothing to choose between", () => {
        const allPrivate = [deck("built", 2, "private"), deck("planned", 3, "private")];

        expect(groupLabels(render(allPrivate))).not.toContain("pages.deck.visibility");
    });

    it("renders nothing at all for a folder that cannot be filtered", () => {
        expect(render([deck("built", 2, "private")]).find(".deck-filters").exists()).toBe(false);
    });

    it("offers only the brackets the decks hold, the unset one as a dash", () => {
        const texts = group(render(), "form.fields.deck_bracket")
            .findAll("button")
            .map(button => button.text());

        expect(texts).toEqual(["2", "3", "–"]);
    });
});

describe("DeckListFilters — selecting", () => {
    it("starts with nothing selected", () => {
        expect(pressed(render())).toEqual([]);
    });

    it("selects a badge on click, and reports it through v-model", async () => {
        const wrapper = render();

        await badge(wrapper, "pages.decks.filters.state", "enums.deck_state.planned").trigger("click");

        expect(lastUpdate(wrapper)).toEqual([{ ...emptyDeckFilters(), state: "planned" }]);
        expect(pressed(wrapper)).toEqual(["enums.deck_state.planned"]);
    });

    it("replaces the group's previous selection", async () => {
        const wrapper = render(mixed, { ...emptyDeckFilters(), bracket: "2" });

        await badge(wrapper, "form.fields.deck_bracket", "3").trigger("click");

        expect(pressed(wrapper)).toEqual(["3"]);
    });

    it("deselects the active badge on a second click", async () => {
        const wrapper = render(mixed, { ...emptyDeckFilters(), visibility: "public" });

        await badge(wrapper, "pages.deck.visibility", "enums.visibility.public").trigger("click");

        expect(lastUpdate(wrapper)).toEqual([emptyDeckFilters()]);
        expect(pressed(wrapper)).toEqual([]);
    });

    it("keeps an active value switchable after its last deck changed", () => {
        // The only planned deck was finished from its row menu: the state
        // group now has one real value, but the active badge must stay.
        const wrapper = render([deck("built", 2, "private"), deck("built", 3, "public")], {
            ...emptyDeckFilters(),
            state: "planned"
        });

        expect(pressed(wrapper)).toEqual(["enums.deck_state.planned"]);
    });
});

describe("DeckListFilters — making the selection obvious", () => {
    it("colours only the active badge, in the colour it has in the deck rows", () => {
        const wrapper = render(mixed, { ...emptyDeckFilters(), state: "built" });

        expect(badge(wrapper, "pages.decks.filters.state", "enums.deck_state.built").classes()).toContain("success");
        expect(badge(wrapper, "pages.decks.filters.state", "enums.deck_state.planned").classes()).not.toContain("info");
    });

    it("swaps the active badge's icon for a check mark", () => {
        const wrapper = render(mixed, { ...emptyDeckFilters(), visibility: "private" });
        const icon = (text: string) =>
            badge(wrapper, "pages.deck.visibility", text).get("use").element.getAttribute("xlink:href");

        expect(icon("enums.visibility.private")).toBe("#check");
        expect(icon("enums.visibility.public")).toBe("#visibility-on");
    });

    it("shows how many decks remain only while filtering", () => {
        expect(render().find(".deck-filters__count").exists()).toBe(false);
        expect(render(mixed, { ...emptyDeckFilters(), state: "built" }).get(".deck-filters__count").text()).toBe(
            "2 / 3"
        );
    });
});

describe("DeckListFilters — accessibility", () => {
    it("names a bare bracket number by its full bracket label", () => {
        const two = badge(render(), "form.fields.deck_bracket", "2");

        expect(two.attributes("aria-label")).toBe("form.fields.deck_bracket_2");
        expect(two.attributes("data-tooltip")).toBe("form.fields.deck_bracket_2");
        expect(badge(render(), "form.fields.deck_bracket", "–").attributes("aria-label")).toBe(
            "form.fields.deck_bracket_unset"
        );
    });

    it("announces the result count after a change, not on opening", async () => {
        setTestMessages({ de: { pages: { decks: { filters: { shown: "{shown} von {total} Decks angezeigt" } } } } });
        const wrapper = render();
        const live = () => wrapper.get('[aria-live="polite"]').text();

        expect(live()).toBe("");

        await badge(wrapper, "pages.decks.filters.state", "enums.deck_state.built").trigger("click");

        expect(live()).toBe("2 von 3 Decks angezeigt");
    });
});
