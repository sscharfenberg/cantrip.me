import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { makeDeckListCard } from "@/test/factories/deckList.ts";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import DeckListLineSearch from "../DeckListLineSearch.vue";

let http: FetchMock;

beforeEach(() => {
    vi.useFakeTimers();
    http = installFetchMock();
});

afterEach(() => {
    vi.useRealTimers();
});

const render = (initialQuery?: string) =>
    mount(DeckListLineSearch, { props: { format: "legacy", quantity: 3, initialQuery } });

/** Type a query and let the debounce and the request run out. */
const type = async (wrapper: ReturnType<typeof render>, query: string) => {
    await wrapper.find("input").setValue(query);
    vi.advanceTimersByTime(500);
    await flushPromises();
};

describe("DeckListLineSearch", () => {
    it("searches once typing pauses, with the format and the line's quantity", async () => {
        http.json("/api/decks/import-list/search", []);
        const wrapper = render();

        await wrapper.find("input").setValue("light");
        vi.advanceTimersByTime(499);
        expect(http.calls).toHaveLength(0);

        vi.advanceTimersByTime(1);
        await flushPromises();
        expect(http.lastCall()?.url).toBe("/api/decks/import-list/search?format=legacy&q=light&quantity=3");
    });

    it("searches the pre-filled query straight away, without waiting for typing", async () => {
        http.json("/api/decks/import-list/search", []);
        const wrapper = render("Lightnig Bolt");
        await flushPromises();

        expect((wrapper.find("input").element as HTMLInputElement).value).toBe("Lightnig Bolt");
        expect(http.lastCall()?.url).toBe("/api/decks/import-list/search?format=legacy&q=Lightnig+Bolt&quantity=3");
    });

    it("shows the spinner inside the search field", async () => {
        http.hang("/api/decks/import-list/search");
        const wrapper = render("Lightning");
        await flushPromises();

        expect(wrapper.find(".form-group__slot .form-group--validating").exists()).toBe(true);
    });

    it("does not search for a single character", async () => {
        const wrapper = render();

        await type(wrapper, "l");

        expect(http.calls).toHaveLength(0);
    });

    it("offers the results and hands back the picked one", async () => {
        const result = { card: makeDeckListCard("Lightning Bolt"), availability: null };
        http.json("/api/decks/import-list/search", [result]);
        const wrapper = render();

        await type(wrapper, "lightning");
        const row = wrapper.find(".candidate-list__row");
        await row.trigger("click");

        expect(row.text()).toContain("LEA #161");
        expect(row.find("img.card-thumb").attributes("src")).toBe("/card-images/Lightning Bolt.jpg");
        expect(wrapper.emitted("pick")).toEqual([[result]]);
    });

    it("shows a spinner while the search is in flight", async () => {
        http.hang("/api/decks/import-list/search");
        const wrapper = render();

        await type(wrapper, "lightning");

        expect(wrapper.find(".loading-spinner").exists()).toBe(true);
    });

    it("says when nothing was found", async () => {
        http.json("/api/decks/import-list/search", []);
        const wrapper = render();

        await type(wrapper, "zzzz");

        expect(wrapper.text()).toContain("pages.deck_list_import.search.no_results");
    });
});
