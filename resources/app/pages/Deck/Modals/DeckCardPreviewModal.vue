<script setup lang="ts">
import { ref } from "vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";

const emit = defineEmits<{ close: [] }>();
defineProps<{
    /** Card name, used as the modal heading. */
    name: string;
    /** Front face image URL. */
    cardImage0: string | null;
    /** Back face image URL (double-faced cards). */
    cardImage1: string | null;
}>();

/** True when the back face is showing. */
const flipped = ref(false);
/** True while the flip animation is running (prevents rapid double-clicks). */
const animating = ref(false);

/**
 * Toggle between the front and back face. Guarded by `animating` so rapid
 * clicks during the CSS flip transition don't stack state changes.
 */
function onFlip() {
    if (animating.value) return;
    animating.value = true;
    flipped.value = !flipped.value;
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ name }}</template>
        <div class="deck-card-preview">
            <div
                class="deck-card-preview__card"
                :class="{ 'deck-card-preview__card--flipped': flipped }"
                @transitionend="animating = false"
            >
                <img v-if="cardImage0" :src="cardImage0" :alt="name" loading="lazy" class="deck-card-preview__front" />
                <img v-if="cardImage1" :src="cardImage1" :alt="name" loading="lazy" class="deck-card-preview__back" />
                <button v-if="cardImage1" type="button" class="deck-card-preview__flip" @click.stop="onFlip">
                    <icon name="flip" />
                </button>
            </div>
        </div>
    </modal>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/z-indexes" as z;
@use "Abstracts/timings" as ti;

.deck-card-preview {
    display: flex;
    justify-content: center;

    &__card {
        position: relative;

        width: 100%;
        max-width: map.get(s.$pages, "deck", "card-preview", "card-image-max");

        border-radius: map.get(s.$pages, "deck", "card-preview", "radius");
        perspective: 800px;

        &--flipped .deck-card-preview__front {
            transform: rotateY(-180deg);
        }

        &--flipped .deck-card-preview__back {
            transform: rotateY(0deg);
        }
    }

    &__front,
    &__back {
        display: block;
        backface-visibility: hidden;

        width: 100%;

        border-radius: inherit;

        transition: transform map.get(ti.$timings, "medium") ease;
    }

    &__back {
        position: absolute;
        inset: 0;

        height: 100%;
        transform: rotateY(180deg);

        object-fit: cover;
    }

    &__flip {
        position: absolute;
        right: 1rem;
        bottom: 50%;
        z-index: map.get(z.$index, "main");

        padding: 0.5rem;
        border: 0;

        background-color: map.get(c.$pages, "deck", "card-preview", "flip", "background");
        color: map.get(c.$pages, "deck", "card-preview", "flip", "surface");
        border-radius: 100dvh;

        cursor: pointer;

        transition:
            background-color map.get(ti.$timings, "fast") linear,
            color map.get(ti.$timings, "fast") linear;

        &:hover {
            background-color: map.get(c.$pages, "deck", "card-preview", "flip", "background-hover");
            color: map.get(c.$pages, "deck", "card-preview", "flip", "surface-hover");
        }

        .icon {
            width: map.get(s.$pages, "deck", "card-preview", "flip-size");
            height: map.get(s.$pages, "deck", "card-preview", "flip-size");
        }
    }
}
</style>
