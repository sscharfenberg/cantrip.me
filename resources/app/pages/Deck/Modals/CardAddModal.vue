<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { computed, onMounted, ref, useTemplateRef, watch } from "vue";
import SearchSyntax from "Components/Card/SearchSyntax.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import Switch from "Components/Form/Switch.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useDeckSearch } from "Composables/useDeckSearch.ts";
import type { DeckCardRow, DeckMeta, DeckSearchResult } from "Types/deckPage.ts";
import CardAddModalResults from "./CardAddModalResults.vue";
const props = defineProps<{
    /** Deck metadata — used to scope card search by format and color identity. */
    deck: DeckMeta;
    /** All cards currently in the deck — used to derive zone counts. */
    cards: DeckCardRow[];
}>();
/** @emits close — Fired when the modal finishes its close animation. */
const emit = defineEmits<{ close: [] }>();
/** Deck-scoped card search state and actions (printings endpoint). */
const { query, results, processing, searchPrintings, reset } = useDeckSearch(props.deck.id);
/** When true, the printings endpoint is called with `include_non_legal=1`. */
const includeNonLegal = ref(false);
const page = usePage();
/** True while a card-add POST is in flight. */
const adding = ref(false);
/** Inline feedback shown after a successful add. */
const feedback = ref<{ name: string; zone: string } | null>(null);
/** Timer for auto-clearing the feedback message. */
let feedbackTimer: ReturnType<typeof setTimeout> | null = null;
/** Current main deck count. */
const mainCount = computed(() => props.cards.filter(c => c.zone === "main").length);
/** Current sideboard count. */
const sideCount = computed(() => props.cards.filter(c => c.zone === "side").length);
/** Whether the sideboard zone is available and has room. */
const canAddToSide = computed(
    () => props.deck.max_sideboard_size > 0 && sideCount.value < props.deck.max_sideboard_size
);
/** Whether the main deck has room (null max = unlimited). */
const canAddToMain = computed(() => props.deck.max_deck_size === null || mainCount.value < props.deck.max_deck_size);
/**
 * Add a card to the deck via the API.
 * Clears results and shows inline feedback on success.
 */
async function addCard(result: DeckSearchResult, zone: string): Promise<void> {
    if (!result.printing || adding.value) return;
    adding.value = true;
    feedback.value = null;
    try {
        const response = await fetch(`/api/decks/${props.deck.id}/cards`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string
            },
            body: JSON.stringify({
                default_card_id: result.printing.id,
                zone
            })
        });
        if (response.ok) {
            results.value = [];
            if (feedbackTimer) clearTimeout(feedbackTimer);
            feedback.value = { name: result.name, zone };
            feedbackTimer = setTimeout(() => {
                feedback.value = null;
            }, 5000);
            router.reload({ only: ["cards", "deck", "violations"] });
        }
    } finally {
        adding.value = false;
    }
}
/** Debounce timer for auto-search on input changes. */
let debounceTimer: ReturnType<typeof setTimeout> | null = null;
/** Template ref for focusing the search input on mount. */
const searchInput = useTemplateRef<HTMLInputElement>("searchInput");
/**
 * Fire the search immediately, cancelling any pending debounce.
 * Used by the form submit handler and the non-legal toggle.
 */
const runSearch = () => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
        debounceTimer = null;
    }
    if (feedbackTimer) clearTimeout(feedbackTimer);
    feedback.value = null;
    searchPrintings(query.value, { includeNonLegal: includeNonLegal.value });
};
/** Debounce input changes — wait 750ms after the last keystroke before searching. */
watch(query, () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(runSearch, 750);
});
/** Re-run the search when the user toggles the non-legal switch. */
const onIncludeNonLegalChange = (checked: boolean) => {
    includeNonLegal.value = checked;
    runSearch();
};
/** Close the modal and clear search state so reopening starts fresh. */
const onClose = () => {
    if (feedbackTimer) clearTimeout(feedbackTimer);
    reset();
    emit("close");
};
onMounted(() => searchInput.value?.focus());
</script>

<template>
    <modal @close="onClose">
        <template #header>
            {{ $t("pages.deck.add.title") }}
        </template>
        <form class="form" @submit.prevent="runSearch">
            <form-group
                :label="$t('pages.deck.add.search_label')"
                :validating="processing"
                addon-icon="search"
                for-id="card_add_query"
            >
                <input
                    ref="searchInput"
                    v-model="query"
                    type="text"
                    name="card_add_query"
                    id="card_add_query"
                    class="form-input"
                    autocomplete="off"
                />
            </form-group>
            <form-group for-id="card_add_include_non_legal" :label="$t('pages.deck.add.include_non_legal')">
                <template #addon>
                    <Switch
                        ref-id="card_add_include_non_legal"
                        :label="$t('pages.deck.add.include_non_legal')"
                        :checked-initially="includeNonLegal"
                        @change="onIncludeNonLegalChange"
                    />
                </template>
            </form-group>
            <form-group>
                <button type="submit" class="btn-primary" :disabled="processing">
                    <icon name="search" />
                    {{ $t("pages.deck.add.submit") }}
                    <loading-spinner v-if="processing" :size="2" />
                </button>
            </form-group>
            <search-syntax v-if="!results.length && !processing && !feedback" />
            <card-add-modal-results
                v-if="results.length"
                :results="results"
                :can-add-to-main="canAddToMain"
                :can-add-to-side="canAddToSide"
                :adding="adding"
                @add="addCard"
            />
            <paragraph v-else-if="feedback && !processing">
                {{ $t("pages.deck.add.added", { name: feedback.name, zone: $t(`enums.zone.${feedback.zone}`) }) }}
            </paragraph>
            <paragraph v-else-if="query.length >= 2 && !processing && !feedback">
                {{ $t("pages.deck.add.no_results") }}
            </paragraph>
        </form>
    </modal>
</template>
