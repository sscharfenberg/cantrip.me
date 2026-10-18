<script setup lang="ts">
/******************************************************************************
 * One card on a banned & restricted list: thumbnail, name and mana cost, in
 * a deck text-view row (the global `_card.scss` partial — so it has to sit
 * inside a `.text-card-group`). Hovering it previews the card; clicking it,
 * or pressing Enter on it, opens the card detail modal.
 *****************************************************************************/
import CardImagePreview from "Components/Card/CardImagePreview.vue";
import ManaCost from "Components/Card/ManaCost.vue";
import type { BannedRestrictedCard } from "Types/bannedRestricted.ts";
defineProps<{
    card: BannedRestrictedCard;
}>();
/** @emits preview — Id of the printing whose detail modal should open. */
const emit = defineEmits<{ preview: [printingId: string] }>();
</script>

<template>
    <li class="card">
        <card-image-preview
            :src="card.printing?.image ?? null"
            :alt="card.name"
            @preview="card.printing && emit('preview', card.printing.id)"
        >
            <button type="button" class="banned-card" :disabled="!card.printing">
                <img
                    v-if="card.printing?.image"
                    :src="card.printing.image"
                    alt=""
                    class="banned-card__thumb"
                    loading="lazy"
                />
                <span v-else class="banned-card__thumb banned-card__thumb--empty" />
                <span class="card__name">{{ card.name }}</span>
            </button>
        </card-image-preview>
        <mana-cost :mana-cost="card.mana_cost" />
    </li>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

// Scoped, so it beats the row's look from the global `_card.scss` partial,
// which lives in @layer components.
.card {
    transition:
        background-color map.get(ti.$timings, "fast") linear,
        border-color map.get(ti.$timings, "fast") linear;

    &:hover,
    &:has(.banned-card:focus-visible) {
        background-color: map.get(c.$pages, "banned-restricted", "card", "background-hover");
        border-color: map.get(c.$pages, "banned-restricted", "card", "border-hover");
    }
}

// CardImagePreview's own scoped padding would beat the global row styles —
// reset it here, as CardViewText does.
:deep(.card-preview__trigger) {
    min-width: 0;
    padding: 0;
}

// The row's hover/click target: a button so the modal is reachable by
// keyboard, reset to look like the deck rows' plain name.
.banned-card {
    display: flex;
    align-items: center;

    width: 100%;
    min-width: 0;
    padding: 0;
    border: 0;
    gap: 1ch;

    background: none;
    color: inherit;

    font: inherit;
    text-align: left;

    cursor: pointer;

    &:disabled {
        cursor: default;
    }

    // Inset: the trigger around the button clips anything drawn outside it
    // (`overflow: hidden`, for the name's ellipsis).
    &:focus-visible {
        outline: 2px solid currentcolor;
        outline-offset: -2px;
    }

    &__thumb {
        overflow: hidden; // a missing image must not spill its alt text
        width: map.get(s.$pages, "banned-restricted", "thumb", "width");
        height: map.get(s.$pages, "banned-restricted", "thumb", "height");
        flex: 0 0 auto;

        border-radius: map.get(s.$pages, "banned-restricted", "thumb", "radius");

        object-fit: cover;
        object-position: top center;

        &--empty {
            display: inline-block;

            background-color: map.get(c.$pages, "banned-restricted", "thumb", "empty");
        }
    }
}
</style>
