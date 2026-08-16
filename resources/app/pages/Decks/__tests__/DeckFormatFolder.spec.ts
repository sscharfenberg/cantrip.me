import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { setPageProps } from "@/test/inertia.ts";
import DeckFormatFolder from "../DeckFormatFolder.vue";
import type { DeckRow } from "../DecksPage.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const deck = (id: string, state: string): DeckRow => ({
    id,
    name: `Deck ${id}`,
    state,
    visibility: "private",
    colors: "W",
    bracket: null,
    card_count: { main: 100, companion: 0, side: 0 },
    total_worth: 42.5,
    last_activity: "2026-04-02T12:00:00+00:00",
    has_description: false,
    has_image: false,
    has_companion: false
});

const render = (props: Record<string, unknown> = {}) =>
    mount(DeckFormatFolder, {
        props: { format: "commander", decks: [deck("a", "built")], initialOpen: false, ...props },
        attachTo: document.body
    });

/** The state-count badges, in render order. */
const badges = (wrapper: ReturnType<typeof render>) => wrapper.findAll(".badges .badge");

beforeEach(() => {
    // `DeckListDetailsLink` formats each deck's worth, which reads the currency
    // off the shared props.
    setPageProps({ auth: { user: { id: "user-1" } }, currency: "eur" });
});

describe("DeckFormatFolder — the header", () => {
    it("names the format", () => {
        expect(render().find(".decklist__format").text()).toBe("enums.card_formats.commander");
    });

    it("counts the planned and built decks separately", () => {
        const wrapper = render({
            decks: [deck("a", "built"), deck("b", "built"), deck("c", "planned")]
        });

        expect(badges(wrapper).map(badge => badge.text())).toEqual(["1", "2"]);
    });

    it("colours the built count as done, distinct from the neutral planned one", () => {
        // `info` is `Badge`'s own default, so only the `success` half of this
        // constrains the component — stated here so the pairing is not read as
        // stronger than it is.
        const wrapper = render({ decks: [deck("a", "built"), deck("b", "planned")] });

        expect(badges(wrapper)[0].classes()).toContain("info");
        expect(badges(wrapper)[1].classes()).toContain("success");
    });

    it("hides a count that would read zero", () => {
        const wrapper = render({ decks: [deck("a", "built")] });

        expect(badges(wrapper)).toHaveLength(1);
        expect(badges(wrapper)[0].classes()).toContain("success");
    });

    it("ignores archived decks, which live on their own page", () => {
        const wrapper = render({ decks: [deck("a", "archived"), deck("b", "archived")] });

        expect(badges(wrapper)).toHaveLength(0);
    });

    it("explains each count in a tooltip", () => {
        const wrapper = render({ decks: [deck("a", "planned")] });

        expect(badges(wrapper)[0].attributes("data-tooltip")).toBe("pages.decks.state_planned");
    });
});

describe("DeckFormatFolder — the body", () => {
    it("lists one entry per deck", () => {
        const wrapper = render({ decks: [deck("a", "built"), deck("b", "planned")], initialOpen: true });

        expect(wrapper.findAllComponents({ name: "DeckListDetailsLink" })).toHaveLength(2);
    });

    it("starts collapsed by default", () => {
        expect(render().find(".collapsible__head").attributes("aria-expanded")).toBe("false");
    });

    it("starts expanded when the URL hash named this format", () => {
        expect(render({ initialOpen: true }).find(".collapsible__head").attributes("aria-expanded")).toBe("true");
    });
});

describe("DeckFormatFolder — toggling", () => {
    it("tells the parent which format was opened, so it can close the others", async () => {
        const wrapper = render();

        await wrapper.find(".collapsible__head").trigger("click");

        expect(wrapper.emitted("toggle")).toEqual([["commander", true]]);
    });

    it("reports a close as well as an open", async () => {
        const wrapper = render({ initialOpen: true });

        await wrapper.find(".collapsible__head").trigger("click");

        expect(wrapper.emitted("toggle")).toEqual([["commander", false]]);
    });

    it("can be closed by the parent without emitting back at it", async () => {
        // The parent closes siblings when one opens; echoing that back would
        // be an event loop.
        const wrapper = render({ initialOpen: true });

        (wrapper.vm as unknown as { close: () => void }).close();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted("toggle")).toBeUndefined();
        expect(wrapper.find(".collapsible__head").attributes("aria-expanded")).toBe("false");
    });

    it("is a no-op to close an already-closed folder", async () => {
        const wrapper = render({ initialOpen: false });

        (wrapper.vm as unknown as { close: () => void }).close();
        await wrapper.vm.$nextTick();

        expect(wrapper.find(".collapsible__head").attributes("aria-expanded")).toBe("false");
        expect(wrapper.emitted("toggle")).toBeUndefined();

        // …and the folder still opens on the next real click, which is what a
        // botched no-op would break by latching the animation guard.
        await wrapper.find(".collapsible__head").trigger("click");

        expect(wrapper.emitted("toggle")).toEqual([["commander", true]]);
    });
});
