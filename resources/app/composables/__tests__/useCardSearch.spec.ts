import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick, ref } from "vue";
import type { Ref } from "vue";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import type { UseCardSearchReturn } from "../useCardSearch.ts";

const ENDPOINT = "/api/card-image";

interface Card {
    id: string;
    name: string;
}

const SOL_RING: Card = { id: "card-1", name: "Sol Ring" };

let http: FetchMock;
/**
 * The "last fired query" buffer is module-level and keyed by endpoint — it
 * deliberately survives the form remount on "save and add more". That makes it
 * survive between tests too, so each test re-imports a fresh module.
 */
let useCardSearch: <T>(endpoint: string, setCode?: Ref<string>) => UseCardSearchReturn<T>;

beforeEach(async () => {
    vi.useFakeTimers();
    vi.resetModules();
    useCardSearch = (await import("../useCardSearch.ts")).useCardSearch;
    http = installFetchMock();
    http.json(ENDPOINT, { total: 1, results: [SOL_RING] });
});

afterEach(() => {
    vi.useRealTimers();
});

/** Advance past the 500ms debounce, letting the fetch chain settle. */
const settle = () => vi.advanceTimersByTimeAsync(500);

/** Type into the search box the way v-model does, then let the watcher run. */
const type = async (search: UseCardSearchReturn<Card>, value: string) => {
    search.searchQuery.value = value;
    await nextTick();
};

describe("useCardSearch — initial state", () => {
    it("starts empty and idle", () => {
        const search = useCardSearch<Card>(ENDPOINT);

        expect(search.searchQuery.value).toBe("");
        expect(search.results.value).toEqual([]);
        expect(search.totalResults.value).toBe(0);
        expect(search.processing.value).toBe(false);
        expect(search.selectedCard.value).toBeNull();
        expect(search.refValue.value).toBe("");
    });
});

describe("useCardSearch — debounced searching", () => {
    it("waits out the debounce before hitting the endpoint", async () => {
        const search = useCardSearch<Card>(ENDPOINT);
        await type(search, "sol ring");

        await vi.advanceTimersByTimeAsync(499);
        expect(http.calls).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1);
        expect(http.calls).toHaveLength(1);
    });

    it("collapses a burst of keystrokes into one request", async () => {
        const search = useCardSearch<Card>(ENDPOINT);

        for (const value of ["s", "so", "sol"]) {
            await type(search, value);
            await vi.advanceTimersByTimeAsync(200);
        }
        await settle();

        expect(http.calls).toHaveLength(1);
        expect(http.lastCall()?.url).toBe(`${ENDPOINT}?q=sol`);
    });

    it("stores results and the pre-cap total", async () => {
        // The server caps the result list but reports the real match count, so
        // the UI can say "20 of 10,535".
        http.json(ENDPOINT, { total: 10_535, results: [SOL_RING] });
        const search = useCardSearch<Card>(ENDPOINT);

        await type(search, "sol");
        await settle();

        expect(search.results.value).toEqual([SOL_RING]);
        expect(search.totalResults.value).toBe(10_535);
    });

    it("falls back to the result count when the server omits a total", async () => {
        http.json(ENDPOINT, { results: [SOL_RING] });
        const search = useCardSearch<Card>(ENDPOINT);

        await type(search, "sol");
        await settle();

        expect(search.totalResults.value).toBe(1);
    });

    it("marks itself busy as soon as the debounce window opens", async () => {
        // Otherwise an empty `results` looks the same during the wait as after
        // a genuinely empty search, and the no-results message flickers.
        const search = useCardSearch<Card>(ENDPOINT);

        await type(search, "sol");

        expect(search.processing.value).toBe(true);
        await settle();
        expect(search.processing.value).toBe(false);
    });

    it("clears results and stops when the box is emptied", async () => {
        const search = useCardSearch<Card>(ENDPOINT);
        await type(search, "sol");
        await settle();

        await type(search, "");

        expect(search.results.value).toEqual([]);
        expect(search.totalResults.value).toBe(0);
        expect(search.processing.value).toBe(false);
        expect(http.calls).toHaveLength(1);
    });

    it("escapes the query rather than splicing it into the URL raw", async () => {
        const search = useCardSearch<Card>(ENDPOINT);

        await type(search, "t:creature o:draw");
        await settle();

        expect(http.lastCall()?.url).toBe(`${ENDPOINT}?q=t%3Acreature%20o%3Adraw`);
    });
});

describe("useCardSearch — set filter", () => {
    it("passes a typed set token straight through when no set is chosen", async () => {
        const setCode = ref("");
        const search = useCardSearch<Card>(ENDPOINT, setCode);

        await type(search, "set:lea black lotus");
        await settle();

        expect(http.lastCall()?.url).toContain(encodeURIComponent("set:lea black lotus"));
    });

    it("lets the dropdown override a typed set token", async () => {
        const setCode = ref("mh3");
        const search = useCardSearch<Card>(ENDPOINT, setCode);

        await type(search, "set:lea black lotus");
        await settle();

        expect(http.lastCall()?.url).toBe(`${ENDPOINT}?q=${encodeURIComponent("set:mh3 black lotus")}`);
    });

    it("strips every alias of the set token, wherever it appears", async () => {
        const setCode = ref("mh3");
        const search = useCardSearch<Card>(ENDPOINT, setCode);

        await type(search, "black s:lea lotus e:2ed");
        await settle();

        expect(http.lastCall()?.url).toBe(`${ENDPOINT}?q=${encodeURIComponent("set:mh3 black lotus")}`);
    });

    it("searches nothing when a set is chosen but no card name typed", async () => {
        // `set:CODE` alone would dump the whole set catalogue, which is not
        // what this picker is for.
        const setCode = ref("mh3");
        const search = useCardSearch<Card>(ENDPOINT, setCode);

        await type(search, "set:lea");
        await settle();

        expect(http.calls).toHaveLength(0);
        expect(search.results.value).toEqual([]);
    });

    it("re-runs the current query when the dropdown changes", async () => {
        const setCode = ref("");
        const search = useCardSearch<Card>(ENDPOINT, setCode);
        await type(search, "black lotus");
        await settle();

        setCode.value = "mh3";
        await nextTick();
        await settle();

        expect(http.calls).toHaveLength(2);
        expect(http.lastCall()?.url).toContain(encodeURIComponent("set:mh3 black lotus"));
    });
});

describe("useCardSearch — selection", () => {
    it("records the chosen card and its id as the form value", async () => {
        const search = useCardSearch<Card>(ENDPOINT);

        search.onCardSelected(SOL_RING);

        expect(search.selectedCard.value).toEqual(SOL_RING);
        expect(search.refValue.value).toBe("card-1");
    });

    it("keeps the query and results so 'change selection' needs no re-fetch", async () => {
        const search = useCardSearch<Card>(ENDPOINT);
        await type(search, "sol");
        await settle();

        search.onCardSelected(SOL_RING);
        search.onClearSelection();

        expect(search.selectedCard.value).toBeNull();
        expect(search.refValue.value).toBe("");
        expect(search.searchQuery.value).toBe("sol");
        expect(search.results.value).toEqual([SOL_RING]);
        expect(http.calls).toHaveLength(1);
    });
});

describe("useCardSearch — recallLastQuery", () => {
    it("declines when nothing has been searched yet, so Tab keeps its default job", () => {
        const search = useCardSearch<Card>(ENDPOINT);

        expect(search.recallLastQuery()).toBe(false);
    });

    it("restores the last query that actually hit the wire", async () => {
        const search = useCardSearch<Card>(ENDPOINT);
        await type(search, "sol ring");
        await settle();
        await type(search, "");

        expect(search.recallLastQuery()).toBe(true);
        expect(search.searchQuery.value).toBe("sol ring");
    });

    it("fires immediately, without waiting out the debounce", async () => {
        const search = useCardSearch<Card>(ENDPOINT);
        await type(search, "sol ring");
        await settle();
        await type(search, "");

        search.recallLastQuery();
        await vi.advanceTimersByTimeAsync(0);

        expect(http.calls).toHaveLength(2);
    });

    it("does not also schedule a debounced duplicate", async () => {
        const search = useCardSearch<Card>(ENDPOINT);
        await type(search, "sol ring");
        await settle();
        await type(search, "");

        search.recallLastQuery();
        await settle();
        await settle();

        expect(http.calls).toHaveLength(2);
    });

    it("survives a form remount, which is the whole point of the module-level buffer", async () => {
        const firstMount = useCardSearch<Card>(ENDPOINT);
        await type(firstMount, "sol ring");
        await settle();

        const afterRemount = useCardSearch<Card>(ENDPOINT);

        expect(afterRemount.recallLastQuery()).toBe(true);
        expect(afterRemount.searchQuery.value).toBe("sol ring");
    });

    it("keeps a separate buffer per endpoint", async () => {
        const cardImages = useCardSearch<Card>(ENDPOINT);
        await type(cardImages, "sol ring");
        await settle();

        const artCrops = useCardSearch<Card>("/api/art-crop");

        expect(artCrops.recallLastQuery()).toBe(false);
    });

    it("remembers the raw input, not the set-rewritten query", async () => {
        const setCode = ref("mh3");
        const search = useCardSearch<Card>(ENDPOINT, setCode);
        await type(search, "black lotus");
        await settle();

        expect(search.lastFiredQuery.value).toBe("black lotus");
    });
});

describe("useCardSearch — failures", () => {
    it("leaves the previous results in place when the server errors", async () => {
        const search = useCardSearch<Card>(ENDPOINT);
        await type(search, "sol");
        await settle();

        http.status(ENDPOINT, 500);
        await type(search, "mox");
        await settle();

        expect(search.results.value).toEqual([SOL_RING]);
    });

    it("stops the spinner and logs when the request fails outright", async () => {
        // The search box would otherwise sit spinning forever after a dropped
        // connection, with no way back short of a reload. Every call site is
        // fire-and-forget, so there is nowhere to rethrow to.
        const error = vi.spyOn(console, "error").mockImplementation(() => {});
        const search = useCardSearch<Card>(ENDPOINT);
        await type(search, "sol");
        await settle();

        http.reject(ENDPOINT, new TypeError("Failed to fetch"));
        await type(search, "mox jet");
        await settle();

        expect(search.processing.value).toBe(false);
        expect(error).toHaveBeenCalled();
        // The previous results stay on screen rather than blanking.
        expect(search.results.value).toEqual([SOL_RING]);
    });

    it("aborts the stale request when a new search starts", async () => {
        http.hang(ENDPOINT);
        const search = useCardSearch<Card>(ENDPOINT);
        await type(search, "sol");
        await settle();

        http.json(ENDPOINT, { total: 1, results: [SOL_RING] });
        await type(search, "mox jet");
        await settle();

        expect(http.callsTo(ENDPOINT)[0].signal?.aborted).toBe(true);
        expect(search.results.value).toEqual([SOL_RING]);
    });
});
