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

const render = () => mount(DeckListLineSearch, { props: { format: "legacy", quantity: 3 } });

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
        await wrapper.find(".line-search__result").trigger("click");

        expect(wrapper.find(".line-search__result").text()).toContain("(LEA) 161");
        expect(wrapper.emitted("pick")).toEqual([[result]]);
    });

    it("says when nothing was found", async () => {
        http.json("/api/decks/import-list/search", []);
        const wrapper = render();

        await type(wrapper, "zzzz");

        expect(wrapper.text()).toContain("pages.deck_list_import.search.no_results");
    });
});
