<script setup lang="ts" generic="T extends { id: string }">
import type { Ref } from "vue";
import { nextTick, toRef, useTemplateRef } from "vue";
import CurrentSelection from "Components/Card/CardSearch/CurrentSelection.vue";
import Results from "Components/Card/CardSearch/Results.vue";
import SearchSyntax from "Components/Card/SearchSyntax.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import { useCardSearch } from "Composables/useCardSearch.ts";
const props = withDefaults(
    defineProps<{
        refId: string;
        /** API endpoint to search against (e.g. "/api/art-crop"). */
        endpoint: string;
        /** i18n key for the form group label. */
        label: string;
        /** i18n key for the input placeholder. */
        placeholder: string;
        /** Icon shown on the form group addon when nothing is selected. */
        searchIcon?: string;
        /** Icon shown on the form group addon when a card is selected. */
        selectedIcon?: string;
        /** Pre-selected card for edit mode. */
        initialCard?: T | null;
        /** form-group required? **/
        required?: boolean;
        /** Validation error message from the parent form. */
        error?: string;
        /** Whether the field is in an invalid state. */
        invalid?: boolean;
        /** When true, the selected card cannot be changed (edit mode). */
        locked?: boolean;
        /**
         * Optional set-code filter. When non-empty, the composable
         * strips any `set:`/`s:`/`e:` token from the user's typed
         * query and replaces it with `set:<setCode>` on the wire — so
         * the dropdown's choice always wins over typed tokens.
         */
        setCode?: string;
    }>(),
    {
        required: false,
        error: "",
        invalid: false,
        locked: false,
        setCode: ""
    }
);
const setCodeRef = toRef(props, "setCode") as Ref<string>;
const emit = defineEmits<{
    /** Emitted when the user selects a card from the search results. */
    selected: [card: T];
    /** Emitted when the user clears the current selection. */
    cleared: [];
}>();
const {
    searchQuery,
    results,
    totalResults,
    processing,
    selectedCard,
    refValue,
    onCardSelected: selectCard,
    onClearSelection
} = useCardSearch<T>(props.endpoint, setCodeRef);
/** Wraps composable selection to also emit the event to the parent. */
function onCardSelected(card: T) {
    selectCard(card);
    emit("selected", card);
}
if (props.initialCard) {
    selectedCard.value = props.initialCard;
    refValue.value = props.initialCard.id;
}
const searchInput = useTemplateRef<HTMLInputElement>("searchInput");
/**
 * Clear the current selection, notify the parent, and re-focus the search
 * input on the next tick so the user can immediately start typing again.
 */
function onClearAndFocus() {
    onClearSelection();
    emit("cleared");
    nextTick(() => searchInput.value?.focus());
}
function focus() {
    searchInput.value?.focus();
}
defineExpose({ focus });
</script>

<template>
    <form-group
        :label="$t(label)"
        :addon-icon="selectedCard ? (selectedIcon ?? searchIcon ?? 'image-search') : (searchIcon ?? 'image-search')"
        :validating="processing"
        :required="required"
        :error="error"
        :invalid="invalid"
    >
        <current-selection v-if="selectedCard" :locked="locked" @clear="onClearAndFocus">
            <slot name="selected" :card="selectedCard as T" />
        </current-selection>
        <template v-else>
            <input
                ref="searchInput"
                type="text"
                class="form-input"
                :id="refId"
                :placeholder="$t(placeholder)"
                v-model="searchQuery"
            />
        </template>
        <input type="hidden" :name="refId" :value="refValue" />
        <template v-if="!selectedCard && results.length === 0" #text>
            <div v-if="searchQuery.length > 1 && !processing" class="no-results">
                {{ $t("card.search.no_results") }}
            </div>
            <search-syntax v-else />
        </template>
    </form-group>
    <Results
        v-if="!selectedCard && results.length > 0"
        :results="results as T[]"
        :total-results="totalResults"
        @change="onCardSelected"
    >
        <template #result="{ card }">
            <slot name="result" :card="card as T" />
        </template>
    </Results>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.no-results {
    padding: map.get(s.$components, "card-search", "padding");
    border: map.get(s.$components, "card-search", "border") solid map.get(c.$state, "error", "border");

    background-color: map.get(c.$state, "error", "background");
    color: map.get(c.$state, "error", "surface");
    border-radius: map.get(s.$components, "card-search", "radius");
}
</style>
