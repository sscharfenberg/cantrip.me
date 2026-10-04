import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { setPageProps } from "@/test/inertia.ts";
import DeckListDetailsLink from "../DeckListDetailsLink.vue";
import type { DeckRow } from "../DecksPage.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const deck = (bracket: number | null): DeckRow => ({
    id: "a",
    name: "Deck a",
    state: "built",
    visibility: "private",
    colors: "W",
    bracket,
    card_count: { main: 100, companion: 0, side: 0 },
    total_worth: 42.5,
    last_activity: "2026-04-02T12:00:00+00:00",
    has_description: false,
    has_image: false,
    has_companion: false
});

const render = (bracket: number | null) => mount(DeckListDetailsLink, { props: { deck: deck(bracket) } });

beforeEach(() => {
    setPageProps({ auth: { user: { id: "user-1" } }, currency: "eur" });
});

describe("DeckListDetailsLink — the bracket badge", () => {
    it("shows the bracket and names it in the tooltip", () => {
        const badge = render(3).find(".deck-bracket");

        expect(badge.text()).toBe("3");
        expect(badge.attributes("data-tooltip")).toBe("form.fields.deck_bracket_3");
    });

    it("still renders without a bracket, so the subgrid keeps every column filled", () => {
        const badge = render(null).find(".deck-bracket");

        expect(badge.exists()).toBe(true);
        expect(badge.text()).toBe("–");
        expect(badge.attributes("data-tooltip")).toBe("form.fields.deck_bracket_unset");
    });
});
