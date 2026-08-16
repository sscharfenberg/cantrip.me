import { beforeEach, describe, expect, it } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { useDeckSearch } from "../useDeckSearch.ts";

const DECK_ID = "deck-1";
const ORACLE = `/api/decks/${DECK_ID}/card-search/oracle`;
const PRINTINGS = `/api/decks/${DECK_ID}/card-search/printings`;

const RESULT = [{ id: "card-1", name: "Sol Ring" }];

let http: FetchMock;

beforeEach(() => {
    http = installFetchMock();
});

describe("useDeckSearch — initial state", () => {
    it("starts empty and idle", () => {
        const search = useDeckSearch(DECK_ID);

        expect(search.query.value).toBe("");
        expect(search.results.value).toEqual([]);
        expect(search.processing.value).toBe(false);
    });

    it("gives each caller its own state, so two searches on one deck don't collide", async () => {
        http.json(ORACLE, RESULT);
        const quickAdd = useDeckSearch(DECK_ID);
        const modal = useDeckSearch(DECK_ID);

        await quickAdd.searchOracle("sol ring");

        expect(quickAdd.results.value).toHaveLength(1);
        expect(modal.results.value).toEqual([]);
    });
});

describe("useDeckSearch — searchOracle", () => {
    it("queries the oracle endpoint with the term", async () => {
        http.json(ORACLE, RESULT);

        await useDeckSearch(DECK_ID).searchOracle("sol ring");

        expect(http.lastCall()?.url).toBe(`${ORACLE}?q=sol+ring`);
    });

    it("stores the results", async () => {
        http.json(ORACLE, RESULT);
        const search = useDeckSearch(DECK_ID);

        await search.searchOracle("sol ring");

        expect(search.results.value).toEqual(RESULT);
    });

    it("clears results without asking the server for a one-character query", async () => {
        http.json(ORACLE, RESULT);
        const search = useDeckSearch(DECK_ID);
        await search.searchOracle("sol ring");

        await search.searchOracle("s");

        expect(http.calls).toHaveLength(1);
        expect(search.results.value).toEqual([]);
    });

    it("counts length after trimming, so whitespace is not a query", async () => {
        const search = useDeckSearch(DECK_ID);

        await search.searchOracle("  s  ");

        expect(http.calls).toHaveLength(0);
    });

    it("escapes the query rather than splicing it into the URL raw", async () => {
        await useDeckSearch(DECK_ID).searchOracle("t:creature o:draw");

        expect(http.lastCall()?.url).toBe(`${ORACLE}?q=t%3Acreature+o%3Adraw`);
    });
});

describe("useDeckSearch — searchPrintings", () => {
    it("queries the printings endpoint with the term", async () => {
        await useDeckSearch(DECK_ID).searchPrintings("sol ring");

        expect(http.lastCall()?.url).toBe(`${PRINTINGS}?q=sol+ring`);
    });

    it("asks for non-legal printings only when told to", async () => {
        const search = useDeckSearch(DECK_ID);

        await search.searchPrintings("sol ring");
        expect(http.lastCall()?.url).not.toContain("include_non_legal");

        await search.searchPrintings("sol ring", { includeNonLegal: true });
        expect(http.lastCall()?.url).toContain("include_non_legal=1");
    });

    it("omits the flag when it is explicitly false", async () => {
        await useDeckSearch(DECK_ID).searchPrintings("sol ring", { includeNonLegal: false });

        expect(http.lastCall()?.url).not.toContain("include_non_legal");
    });

    it("applies the same two-character minimum", async () => {
        await useDeckSearch(DECK_ID).searchPrintings("s");

        expect(http.calls).toHaveLength(0);
    });
});

describe("useDeckSearch — in-flight handling", () => {
    it("raises processing for the duration of the request", async () => {
        const search = useDeckSearch(DECK_ID);
        let duringRequest: boolean | null = null;
        http.on(ORACLE, () => {
            duringRequest = search.processing.value;
            return new Response("[]", { status: 200 });
        });

        await search.searchOracle("sol ring");

        expect(duringRequest).toBe(true);
        expect(search.processing.value).toBe(false);
    });

    it("aborts the previous request when a new search starts", async () => {
        // Otherwise a slow first response could land after a fast second one
        // and overwrite the newer results.
        const search = useDeckSearch(DECK_ID);
        http.hang(ORACLE);
        const stale = search.searchOracle("sol");

        http.json(PRINTINGS, RESULT);
        await search.searchPrintings("sol ring");
        await stale;

        expect(search.results.value).toEqual(RESULT);
        expect(http.callsTo(ORACLE)[0].signal?.aborted).toBe(true);
        expect(http.callsTo(PRINTINGS)[0].signal?.aborted).toBe(false);
    });

    it("aborts the in-flight request on reset rather than letting it land later", async () => {
        // Without the abort in `reset()` the hung request would never settle
        // and this test would fail on timeout instead of on an assertion.
        http.json(ORACLE, RESULT);
        const search = useDeckSearch(DECK_ID);
        await search.searchOracle("sol ring");

        http.hang(ORACLE);
        const pending = search.searchOracle("mox");
        search.reset();
        await pending;

        expect(http.callsTo(ORACLE)[1].signal?.aborted).toBe(true);
        expect(search.results.value).toEqual([]);
        expect(search.processing.value).toBe(false);
    });

    it("sends the query untrimmed, even though the length check trims it", () => {
        // Documented as-is: the backend parser tolerates the padding, and
        // trimming on the wire would be a separate decision.
        const search = useDeckSearch(DECK_ID);
        void search.searchOracle("  sol  ");

        expect(http.lastCall()?.url).toBe(`${ORACLE}?q=++sol++`);
    });

    it("leaves the previous results in place when the server errors", async () => {
        http.json(ORACLE, RESULT);
        const search = useDeckSearch(DECK_ID);
        await search.searchOracle("sol ring");

        http.status(ORACLE, 500);
        await search.searchOracle("mox jet");

        expect(search.results.value).toEqual(RESULT);
        expect(search.processing.value).toBe(false);
    });

    it("rethrows a non-abort failure so the caller can surface it", async () => {
        http.reject(ORACLE, new TypeError("Failed to fetch"));
        const search = useDeckSearch(DECK_ID);

        await expect(search.searchOracle("sol ring")).rejects.toThrow("Failed to fetch");
        expect(search.processing.value).toBe(false);
    });
});

describe("useDeckSearch — reset", () => {
    it("clears the query, the results and the busy flag", async () => {
        http.json(ORACLE, RESULT);
        const search = useDeckSearch(DECK_ID);
        search.query.value = "sol ring";
        await search.searchOracle("sol ring");

        search.reset();

        expect(search.query.value).toBe("");
        expect(search.results.value).toEqual([]);
        expect(search.processing.value).toBe(false);
    });

    it("is safe to call before any search has run", () => {
        expect(() => useDeckSearch(DECK_ID).reset()).not.toThrow();
    });
});
