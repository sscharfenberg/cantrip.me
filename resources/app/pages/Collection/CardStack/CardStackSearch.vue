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
        @selected="emit('selected', $event)"
        @cleared="emit('cleared')"
    >
        <template #result="{ card }">
            <card-face-image
                v-if="(card as DefaultCardImage).card_image_0"
                :card="card as DefaultCardImage"
                interactive
            />
        </template>
        <template #selected="{ card }">
            <card-face-image v-if="(card as DefaultCardImage).card_image_0" :card="card as DefaultCardImage" />
        </template>
    </card-search>
</template>

<style lang="scss" scoped>
// Hide result entries whose card has no face-image — gated by the v-if
// on <card-face-image> above. The :has() selector keeps the empty <li>
// (rendered by Results.vue, outside this component) from leaving a
// clickable hole in the grid.
:deep(.result:not(:has(.face-image))) {
    display: none;
}
</style>
