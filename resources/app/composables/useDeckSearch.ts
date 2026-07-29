import { ref } from "vue";
import type { DeckSearchResult } from "Types/deckPage.ts";

/**
 * Per-deck card search state and actions.
 *
 * Exposes two fetch methods backed by the two card-search endpoints:
 *
 *  - `searchOracle(q)` → `/api/decks/{deck}/card-search/oracle`
 *    Oracle-level search for the quick-add input. Ignores `set:` / `cn:`
 *    tokens and returns distinct oracle cards with their newest printing.
 *  - `searchPrintings(q, { includeNonLegal })` → `/api/decks/{deck}/card-search/printings`
 *    Printing-level search for the full card-add modal. Honors `set:` /
 *    `cn:` tokens and can return multiple printings of the same oracle
 *    card. When `includeNonLegal` is true, format-legality filters are
 *    dropped but color identity is still enforced.
 *
 * Debouncing is intentionally NOT handled here — the consuming component
 * decides when to fire (e.g. a `watch` on the input for auto-search plus
 * an explicit submit button that cancels the pending debounce).
 *
 * State is per-instance (not module-level) so multiple consumers of the
 * same deck each own their own query/results without clobbering each other.
 *
 * @param deckId - UUID of the deck the search is scoped to.
 */
export function useDeckSearch(deckId: string) {
    /** Current search query (component binds this via v-model). */
    const query = ref("");
    /** Results from the most recent successful fetch. */
    const results = ref<DeckSearchResult[]>([]);
    /** True while a search XHR is in flight. */
    const processing = ref(false);
    /** AbortController for the current in-flight request; a new search cancels stale ones. */
    let abortController: AbortController | null = null;

    /**
     * Perform a GET against the given URL, canceling any in-flight request
     * first. Populates `results` on success; leaves them untouched on abort
     * and rethrows any other error so callers can surface it.
     */
    async function fetchFromEndpoint(url: string): Promise<void> {
        if (abortController) abortController.abort();
        abortController = new AbortController();
        processing.value = true;
        try {
            const response = await fetch(url, { signal: abortController.signal });
            if (response.ok) {
                results.value = (await response.json()) as DeckSearchResult[];
            }
        } catch (e) {
            if (e instanceof DOMException && e.name === "AbortError") return;
            throw e;
        } finally {
            processing.value = false;
        }
    }

    /**
     * Oracle-level search. Queries shorter than 2 characters clear results
     * instead of hitting the server.
     */
    async function searchOracle(q: string): Promise<void> {
        if (q.trim().length < 2) {
            results.value = [];
            return;
        }
        const params = new URLSearchParams({ q });
        await fetchFromEndpoint(`/api/decks/${deckId}/card-search/oracle?${params}`);
    }

    /**
     * Printing-level search. `includeNonLegal=true` drops the legality
     * filter while keeping color identity enforced.
     */
    async function searchPrintings(q: string, options: { includeNonLegal?: boolean } = {}): Promise<void> {
        if (q.trim().length < 2) {
            results.value = [];
            return;
        }
        const params = new URLSearchParams({ q });
        if (options.includeNonLegal) {
            params.set("include_non_legal", "1");
        }
        await fetchFromEndpoint(`/api/decks/${deckId}/card-search/printings?${params}`);
    }

    /** Clear the current query and results (e.g. when closing the modal). */
    function reset(): void {
        if (abortController) abortController.abort();
        query.value = "";
        results.value = [];
        processing.value = false;
    }

    return {
        query,
        results,
        processing,
        searchOracle,
        searchPrintings,
        reset
    };
}

export type UseDeckSearchReturn = ReturnType<typeof useDeckSearch>;
