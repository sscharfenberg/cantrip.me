<script setup lang="ts">
/******************************************************************************
 * Search for a card to replace an unresolved deck list line with.
 *
 * Deck-less on purpose: the deck does not exist while the review page is
 * open, so this asks `GET /api/decks/import-list/search` rather than the
 * deck-scoped card search. Results come with the printing the import would
 * use and its availability, so picking one needs no second request.
 *****************************************************************************/
import { onBeforeUnmount, onMounted, ref } from "vue";
import FormGroup from "Components/Form/FormGroup.vue";
import type { DeckListCandidate } from "Types/deckListImport.ts";
import DeckListCandidateList from "./DeckListCandidateList.vue";
const props = defineProps<{
    /** The deck's format — results are flagged against it. */
    format: string;
    /** The line's quantity, for the availability of each result. */
    quantity: number;
    /** Pre-filled query — the pasted name — searched right away. */
    initialQuery?: string;
}>();
const emit = defineEmits<{
    /** The user picked a result. */
    pick: [candidate: DeckListCandidate];
}>();
/** Wait after the last keystroke before searching. */
const DEBOUNCE_MS = 500;
const query = ref(props.initialQuery ?? "");
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
onMounted(() => {
    if (query.value.trim() !== "") void search();
});
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <div class="line-search">
        <form-group class="line-search__field" addon-icon="search" :validating="searching">
            <input
                v-model="query"
                type="search"
                class="form-input"
                :aria-label="$t('pages.deck_list_import.search.label')"
                :placeholder="$t('pages.deck_list_import.search.placeholder')"
                @input="onInput"
            />
        </form-group>
        <deck-list-candidate-list v-if="results.length" :candidates="results" @pick="emit('pick', $event)" />
        <p v-else-if="searched && !searching" class="line-search__empty">
            {{ $t("pages.deck_list_import.search.no_results") }}
        </p>
    </div>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/sizes" as s;

.line-search {
    display: flex;
    flex-direction: column;

    gap: 0.75ex;
}

/* The shared search field — addon icon, spinner inside while searching —
   without the form layout's label column. */
.line-search__field {
    max-width: map.get(s.$pages, "deck-list-import", "search", "max-width");

    :deep(> .label) {
        display: none;
    }

    :deep(.form-group__input) {
        flex: 1 1 auto;
    }
}

.line-search__empty {
    margin: 0;
}
</style>
