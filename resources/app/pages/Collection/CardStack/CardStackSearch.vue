<script setup lang="ts">
import { useTemplateRef } from "vue";
import CardFaceImage from "Components/Card/CardFaceImage.vue";
import CardSearch from "Components/Card/CardSearch/CardSearch.vue";
import type { DefaultCardImage } from "Types/defaultCardImage";
const props = defineProps<{
    /** Validation error message for the default_card_id field. */
    error?: string;
    /** Whether the field is in an invalid state. */
    invalid?: boolean;
    /** Pre-selected card for edit mode. */
    card?: DefaultCardImage | null;
    /** When true, the card cannot be changed (edit mode). */
    locked?: boolean;
    /**
     * Set-code filter passed through to the underlying CardSearch.
     * Drives the "restrict results to set" dropdown on the parent page.
     */
    setCode?: string;
    /**
     * Forwarded to CardSearch → SearchSyntax. Set on the add-cards page
     * to surface the +/- amount and Enter-to-save-and-add-more hints.
     */
    keyboardShortcuts?: boolean;
    /**
     * Forwarded to CardSearch. Enables Tab-to-recall on the empty
     * search input so the user can repeat the last query without
     * retyping after "save and add more".
     */
    recallable?: boolean;
}>();
const emit = defineEmits<{
    /** Emitted when the user selects a card from the search results. */
    selected: [card: DefaultCardImage];
    /** Emitted when the user clears the current selection. */
    cleared: [];
}>();
const initialCard: DefaultCardImage | null = props.card ?? null;
const searchRef = useTemplateRef<{ focus: () => void }>("searchRef");
defineExpose({
    focus: () => searchRef.value?.focus()
});
</script>

<template>
    <card-search
        ref="searchRef"
        ref-id="default_card_id"
        endpoint="/api/card-image"
        label="form.fields.card"
        placeholder="card.search.placeholder.face"
        search-icon="image-search"
        selected-icon="container-image"
        :initial-card="initialCard"
        :locked="locked ?? false"
        :required="true"
        :error="error"
        :invalid="invalid"
        :set-code="setCode ?? ''"
        :keyboard-shortcuts="keyboardShortcuts ?? false"
        :recallable="recallable ?? false"
        @selected="emit('selected', $event)"
        @cleared="emit('cleared')"
    >
        <template #result="{ card }">
            <card-face-image
                :card="card as DefaultCardImage"
                interactive
                :translated-name="(card as DefaultCardImage).matched_translation?.name"
                :translated-lang="(card as DefaultCardImage).matched_translation?.lang"
            />
        </template>
        <template #selected="{ card }">
            <card-face-image
                v-if="(card as DefaultCardImage).card_image_0"
                :card="card as DefaultCardImage"
                :translated-name="(card as DefaultCardImage).matched_translation?.name"
                :translated-lang="(card as DefaultCardImage).matched_translation?.lang"
            />
        </template>
    </card-search>
</template>
