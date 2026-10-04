<script setup lang="ts">
/******************************************************************************
 * Search for a card to replace an unresolved deck list line with.
 *
 * Deck-less on purpose: the deck does not exist while the review page is
 * open, so this asks `GET /api/decks/import-list/search` rather than the
 * deck-scoped card search. Results come with the printing the import would
 * use and its availability, so picking one needs no second request.
 *****************************************************************************/
import { onBeforeUnmount, ref } from "vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckListCandidate } from "Types/deckListImport.ts";
const props = defineProps<{
    /** The deck's format — results are flagged against it. */
    format: string;
    /** The line's quantity, for the availability of each result. */
    quantity: number;
}>();
const emit = defineEmits<{
    /** The user picked a result. */
    pick: [candidate: DeckListCandidate];
}>();
/** Wait after the last keystroke before searching. */
const DEBOUNCE_MS = 500;
const query = ref("");
const results = ref<DeckListCandidate[]>([]);
const searching = ref(false);
/** True once a search has answered, so "no results" is not shown before the first one. */
const searched = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let controller: AbortController | null = null;
/** Run the search for the current query, cancelling one still in flight. */
const search = async (): Promise<void> => {
    controller?.abort();
    const q = query.value.trim();
    if (q.length < 2) {
        results.value = [];
        searched.value = false;
        return;
    }
    controller = new AbortController();
    searching.value = true;
    try {
        const params = new URLSearchParams({ format: props.format, q, quantity: String(props.quantity) });
        const response = await fetch(`/api/decks/import-list/search?${params}`, {
            headers: { Accept: "application/json" },
            signal: controller.signal
        });
        results.value = response.ok ? ((await response.json()) as DeckListCandidate[]) : [];
        searched.value = true;
    } catch (error) {
        if ((error as Error).name !== "AbortError") results.value = [];
    } finally {
        searching.value = false;
    }
};
/** Debounce typing into the search. */
const onInput = () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(search, DEBOUNCE_MS);
};
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <div class="line-search">
        <div class="form-input line-search__input">
            <icon name="search" />
            <input
                v-model="query"
                type="search"
                :aria-label="$t('pages.deck_list_import.search.label')"
                :placeholder="$t('pages.deck_list_import.search.placeholder')"
                @input="onInput"
            />
        </div>
        <ul v-if="results.length" class="line-search__results">
            <li v-for="result in results" :key="result.card.oracle_card_id">
                <button type="button" class="btn-default line-search__result" @click="emit('pick', result)">
                    {{ result.card.name }}
                    <span class="line-search__printing">
                        ({{ result.card.set_code.toUpperCase() }}) {{ result.card.collector_number }}
                    </span>
                </button>
            </li>
        </ul>
        <p v-else-if="searched && !searching" class="line-search__empty">
            {{ $t("pages.deck_list_import.search.no_results") }}
        </p>
    </div>
</template>

<style lang="scss" scoped>
.line-search {
    display: flex;
    flex-direction: column;

    gap: 0.5ex;
}

.line-search__input {
    display: flex;
    align-items: center;

    gap: 0.5ch;

    input {
        width: 100%;
    }
}

.line-search__results {
    display: flex;
    flex-wrap: wrap;

    padding: 0;
    margin: 0;
    gap: 0.5ex 0.5ch;

    list-style: none;
}

.line-search__printing {
    opacity: 0.75;
}

.line-search__empty {
    margin: 0;
}
</style>
