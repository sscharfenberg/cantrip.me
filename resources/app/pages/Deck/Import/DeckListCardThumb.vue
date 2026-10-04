<script setup lang="ts">
/******************************************************************************
 * Small front-face thumbnail of the printing a deck list line would import,
 * with an empty placeholder of the same size when the printing has no image —
 * so rows line up either way.
 *****************************************************************************/
import type { DeckListCard } from "Types/deckListImport.ts";
defineProps<{
    card: DeckListCard;
}>();
</script>

<template>
    <img v-if="card.image" :src="card.image" :alt="card.name" class="card-thumb" loading="lazy" />
    <span v-else class="card-thumb card-thumb--empty" aria-hidden="true" />
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.card-thumb {
    overflow: hidden; /* a missing image must not spill its alt text */
    width: map.get(s.$pages, "deck-list-import", "thumb", "width");
    height: map.get(s.$pages, "deck-list-import", "thumb", "height");
    flex: 0 0 auto;

    border-radius: map.get(s.$pages, "deck-list-import", "thumb", "radius");

    object-fit: cover;
    object-position: top center;

    &--empty {
        display: inline-block;

        background-color: map.get(c.$pages, "deck-list-import", "thumb", "empty");
    }
}
</style>
