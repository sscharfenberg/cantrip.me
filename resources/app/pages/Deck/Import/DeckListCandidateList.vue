<script setup lang="ts">
/******************************************************************************
 * Search results for an unresolved deck list line, as a zebra-striped list;
 * hovering a row previews the card image. Picking a row hands the
 * card back; each row is a native button for keyboard and screen readers,
 * styled as a list row rather than as a button.
 *****************************************************************************/
import CardImagePreview from "Components/Card/CardImagePreview.vue";
import CollectionAvailabilityBadge from "Components/Deck/CollectionAvailabilityBadge.vue";
import type { DeckListCandidate } from "Types/deckListImport.ts";
import DeckListCardThumb from "./DeckListCardThumb.vue";
defineProps<{
    candidates: DeckListCandidate[];
}>();
const emit = defineEmits<{
    /** The user picked this card. */
    pick: [candidate: DeckListCandidate];
}>();
</script>

<template>
    <ul class="candidate-list">
        <li v-for="candidate in candidates" :key="candidate.card.oracle_card_id">
            <card-image-preview :src="candidate.card.image" :alt="candidate.card.name">
                <button type="button" class="candidate-list__row" @click="emit('pick', candidate)">
                    <deck-list-card-thumb :card="candidate.card" />
                    <span class="candidate-list__name">{{ candidate.card.name }}</span>
                    <span class="candidate-list__printing">
                        {{ candidate.card.set_code.toUpperCase() }} #{{ candidate.card.collector_number }}
                    </span>
                    <collection-availability-badge
                        v-if="candidate.availability"
                        :availability="candidate.availability"
                    />
                </button>
            </card-image-preview>
        </li>
    </ul>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.candidate-list {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$pages, "deck-list-import", "list", "gap");

    list-style: none;

    li:nth-child(odd) :deep(.card-preview__trigger) {
        padding: 0;
    }

    .candidate-list__row {
        background-color: map.get(c.$pages, "deck-list-import", "row", "background", "odd");
    }

    /* Same specificity as the zebra rule above, and later, so hover wins on odd rows too. */
    li .candidate-list__row:hover,
    li .candidate-list__row:focus-visible {
        background-color: map.get(c.$pages, "deck-list-import", "row", "background", "hover");
        color: map.get(c.$pages, "deck-list-import", "row", "surface", "hover");
    }
}

.candidate-list__row {
    display: flex;
    align-items: center;

    width: 100%;
    padding: map.get(s.$pages, "deck-list-import", "row", "padding");
    border: map.get(s.$pages, "deck-list-import", "row", "border") solid
        map.get(c.$pages, "deck-list-import", "row", "border");
    gap: map.get(s.$pages, "deck-list-import", "row", "gap");

    background-color: map.get(c.$pages, "deck-list-import", "row", "background", "even");
    color: map.get(c.$pages, "deck-list-import", "row", "surface", "default");
    border-radius: map.get(s.$pages, "deck-list-import", "row", "radius");

    font: inherit;
    text-align: left;

    cursor: pointer;

    transition:
        background-color map.get(ti.$timings, "fast") linear,
        color map.get(ti.$timings, "fast") linear;
}

.candidate-list__name {
    font-weight: 600;
}

.candidate-list__printing {
    opacity: 0.75;

    margin-right: auto;

    font-size: 0.875em;
}
</style>
