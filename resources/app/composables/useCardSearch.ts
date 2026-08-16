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
 * Module-level "last raw query that triggered an XHR" per endpoint. Keyed
 * by endpoint string so two CardSearch instances against different APIs
 * (e.g. /api/card-image vs /api/art-crop) keep their own recall buffers.
 * Surviving the form remount on "save and add more" is the whole point —
 * the `useAddCardsDefaults` composable resets the form, but this state
 * lives outside the component tree and is preserved.
 */
const lastFiredQueriesByEndpoint = new Map<string, Ref<string>>();

function getLastFiredQueryRef(endpoint: string): Ref<string> {
    let r = lastFiredQueriesByEndpoint.get(endpoint);
    if (!r) {
        r = ref("");
        lastFiredQueriesByEndpoint.set(endpoint, r);
    }
    return r;
}

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
    /** Last raw query that hit the wire, per endpoint. Drives Tab-to-recall in CardSearch. */
    const lastFiredQuery = getLastFiredQueryRef(endpoint);

    /** Timer handle for debouncing search input. */
    let debounceTimer: ReturnType<typeof setTimeout> | null = null;
    /** AbortController for the current in-flight request, so a new search cancels stale ones. */
    let abortController: AbortController | null = null;
    /**
     * Set by `recallLastQuery` so the watcher (which fires async after we
     * mutate `searchQuery`) doesn't also schedule a duplicate debounced
     * XHR on top of the immediate one we just kicked off.
     */
    let suppressNextWatcher = false;

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
        lastFiredQuery.value = rawQuery.trim();
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
            // An abort means a newer search is already in flight and has set
            // `processing` itself — leave it alone.
            if (e instanceof DOMException && e.name === "AbortError") return;
            // Anything else is a real failure. Logged rather than rethrown:
            // every call site is fire-and-forget (`void searchCards(…)` and the
            // debounce timer), so a rethrow had no one to catch it and simply
            // became an unhandled rejection. Stopping the spinner is what the
            // user needs — the results list keeps its previous contents.
            console.error(e);
            processing.value = false;
            return;
        }
        processing.value = false;
    }

    /**
     * Schedule (or fire) the next search. Extracted from the watcher so
     * `recallLastQuery` can bypass debounce when the user explicitly
     * asked to repeat the previous search.
     */
    function scheduleSearch(immediate: boolean): void {
        if (debounceTimer) clearTimeout(debounceTimer);
        const query = buildQuery(searchQuery.value);
        if (!query) {
            processing.value = false;
            results.value = [];
            totalResults.value = 0;
            return;
        }
        processing.value = true;
        if (immediate) {
            void searchCards(searchQuery.value);
        } else {
            debounceTimer = setTimeout(() => searchCards(searchQuery.value), 500);
        }
    }

    /**
     * Called when the user clicks a result.
     *
     * Results and searchQuery are intentionally NOT cleared — they stay
     * in memory so that clicking "Change selection" later can restore
     * the previous input + result list without re-fetching. The Results
     * component is hidden visually via `v-if="!selectedCard"` in
     * CardSearch.vue while a card is selected.
     */
    function onCardSelected(card: T) {
        selectedCard.value = card;
        refValue.value = (card as Record<string, unknown>).id as string;
    }

    /** Called when the user clicks "Change selection". */
    function onClearSelection() {
        selectedCard.value = null;
        refValue.value = "";
        // searchQuery and results are preserved from before the
        // selection, so the input repopulates and the previous result
        // list reappears without a re-fetch.
    }

    /**
     * Repopulate the search input with the last query that triggered an
     * XHR and fire the search immediately (no debounce). Returns false
     * when there's nothing to recall yet so the caller can let the Tab
     * keystroke pass through to default focus-navigation behavior.
     */
    function recallLastQuery(): boolean {
        if (!lastFiredQuery.value) return false;
        suppressNextWatcher = true;
        searchQuery.value = lastFiredQuery.value;
        scheduleSearch(true);
        return true;
    }

    /**
     * Debounce search input changes (and setCode changes, when bound)
     * by 500 ms before calling the API. setCode flips re-run the
     * current query so the result list reacts to dropdown changes.
     *
     * `processing` is flipped on synchronously inside `scheduleSearch`
     * (not just inside `searchCards`) so consumers can distinguish
     * "debounce window is open, search hasn't happened yet" from
     * "search completed with no results" — otherwise an empty `results`
     * array reads the same in both states and a no-results message
     * flickers during the wait.
     */
    watch([searchQuery, () => setCode?.value ?? ""], () => {
        if (suppressNextWatcher) {
            suppressNextWatcher = false;
            return;
        }
        scheduleSearch(false);
    });

    return {
        searchQuery,
        results,
        totalResults,
        processing,
        selectedCard,
        refValue,
        lastFiredQuery,
        onCardSelected,
        onClearSelection,
        recallLastQuery
    };
}

export type UseCardSearchReturn<T> = ReturnType<typeof useCardSearch<T>>;
