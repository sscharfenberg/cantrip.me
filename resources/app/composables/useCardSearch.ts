import type { Ref } from "vue";
import { ref, watch } from "vue";

/** Server response shape for paged card-search endpoints. `total` is the
 *  number of matches before the server-side cap is applied, so the UI can
 *  show "20 of 10,535" even when only the first 100 are returned. */
interface CardSearchResponse<T> {
    total: number;
    results: T[];
}

/**
 * Backend set-filter tokens we strip from raw user input before
 * prepending the dropdown-driven setCode. Matches `set:abc`, `s:abc`,
 * `e:abc` anywhere in the query (leading/middle/trailing). Case-insensitive.
 */
const SET_TOKEN_RE = /(?:^|\s)(?:set|s|e):\S+/gi;

/**
 * Reactive state and helpers for a debounced card search against a JSON API endpoint.
 *
 * @param endpoint  API endpoint to fetch from (e.g. "/api/card-image").
 * @param setCode   Optional reactive set-code filter. When present and
 *                  non-empty, any `set:`/`s:`/`e:` token the user types
 *                  in the input is stripped out and replaced by
 *                  `set:<setCode>` on the wire — the dropdown's choice
 *                  wins over typed tokens.
 */
export function useCardSearch<T>(endpoint: string, setCode?: Ref<string>) {
    /** The current text in the search input, bound via v-model. */
    const searchQuery = ref("");
    /** Card results returned by the search endpoint (capped server-side). */
    const results = ref<T[]>([]);
    /** Total matches the server found before applying its result cap. */
    const totalResults = ref(0);
    /** True while a search XHR is in flight. */
    const processing = ref(false);
    /** The currently selected card result. */
    const selectedCard = ref<T | null>(null);
    /** The hidden form value (card id). */
    const refValue = ref("");

    /** Timer handle for debouncing search input. */
    let debounceTimer: ReturnType<typeof setTimeout> | null = null;
    /** AbortController for the current in-flight request, so a new search cancels stale ones. */
    let abortController: AbortController | null = null;

    /**
     * Build the actual query string sent to the server from the raw
     * user input.
     *
     * - No dropdown set selected → pass the user's query through
     *   unchanged. Any typed `set:`/`s:`/`e:` token reaches the
     *   backend's CardSearchParser and works as before.
     * - Dropdown set selected → strip any conflicting `set:`/`s:`/`e:`
     *   token the user typed and prepend `set:<code>`. The dropdown
     *   always wins. If nothing's left after stripping, the query is
     *   empty (set:CODE alone would dump a whole set's catalogue and
     *   isn't useful here).
     */
    function buildQuery(rawQuery: string): string {
        const code = setCode?.value?.trim() ?? "";
        if (!code) {
            return rawQuery.trim();
        }
        const namePart = rawQuery.replace(SET_TOKEN_RE, " ").replace(/\s+/g, " ").trim();
        if (!namePart) return "";
        return `set:${code} ${namePart}`;
    }

    /**
     * Fetch matching cards from the API and populate results.
     * Clears results if the effective query is empty.
     */
    async function searchCards(rawQuery: string) {
        const query = buildQuery(rawQuery);
        if (!query) {
            results.value = [];
            totalResults.value = 0;
            return;
        }
        if (abortController) abortController.abort();
        abortController = new AbortController();
        processing.value = true;
        try {
            const response = await fetch(`${endpoint}?q=${encodeURIComponent(query)}`, {
                signal: abortController.signal
            });
            if (response.ok) {
                const data = (await response.json()) as CardSearchResponse<T>;
                if (data) {
                    results.value = data.results ?? [];
                    totalResults.value = data.total ?? data.results?.length ?? 0;
                }
            }
        } catch (e) {
            if (e instanceof DOMException && e.name === "AbortError") return;
            throw e;
        }
        processing.value = false;
    }

    /** Called when the user clicks a result. */
    function onCardSelected(card: T) {
        selectedCard.value = card;
        refValue.value = (card as Record<string, unknown>).id as string;
        results.value = [];
        totalResults.value = 0;
    }

    /** Called when the user clicks "Change selection". */
    function onClearSelection() {
        selectedCard.value = null;
        refValue.value = "";
        searchQuery.value = "";
    }

    /**
     * Debounce search input changes (and setCode changes, when bound)
     * by 500 ms before calling the API. setCode flips re-run the
     * current query so the result list reacts to dropdown changes.
     *
     * `processing` is flipped on synchronously here (not just inside
     * `searchCards`) so consumers can distinguish "debounce window is
     * open, search hasn't happened yet" from "search completed with no
     * results" — otherwise an empty `results` array reads the same in
     * both states and a no-results message flickers during the wait.
     */
    watch([searchQuery, () => setCode?.value ?? ""], () => {
        if (debounceTimer) clearTimeout(debounceTimer);
        const query = buildQuery(searchQuery.value);
        if (!query) {
            processing.value = false;
            results.value = [];
            totalResults.value = 0;
            return;
        }
        processing.value = true;
        debounceTimer = setTimeout(() => searchCards(searchQuery.value), 500);
    });

    return {
        searchQuery,
        results,
        totalResults,
        processing,
        selectedCard,
        refValue,
        onCardSelected,
        onClearSelection
    };
}

export type UseCardSearchReturn<T> = ReturnType<typeof useCardSearch<T>>;
