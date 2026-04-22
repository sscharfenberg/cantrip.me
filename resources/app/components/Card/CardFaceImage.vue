<script setup lang="ts">
import { ref } from "vue";
import Icon from "Components/UI/Icon.vue";
import type { DefaultCardImage } from "Types/defaultCardImage.ts";
defineProps<{
    card: DefaultCardImage;
    /** When true, shows a zoom effect on hover. Use in clickable contexts (e.g. results grid). */
    interactive?: boolean;
    /** CSS selector for the FloatingVue tooltip container. Defaults to `body`. */
    tooltipContainer?: string;
}>();
/** True when the back face is showing. */
const flipped = ref(false);
/** True while the flip animation is running (prevents rapid double-clicks). */
const animating = ref(false);
function onFlip() {
    if (animating.value) return;
    animating.value = true;
    flipped.value = !flipped.value;
}
</script>

<template>
    <div
        class="face-image"
        :class="{ 'face-image--interactive': interactive, 'face-image--flipped': flipped }"
        @transitionend="animating = false"
    >
        <img :src="card.card_image_0 ?? undefined" :alt="card.name" loading="lazy" class="face-image__front" />
        <img
            v-if="card.card_image_1"
            :src="card.card_image_1"
            :alt="card.name"
            loading="lazy"
            class="face-image__back"
        />
        <button type="button" class="face-image__flip" v-if="card.card_image_1" @click.stop="onFlip">
            <icon name="flip" />
        </button>
        <div class="face-image__panel">
            <span class="face-image__panel-line">
                <icon name="star" :size="0" />
                {{ card.cn }}
                <img
                    v-if="card.set.path"
                    :src="card.set.path"
                    class="face-image__set"
                    :alt="`${card.set.code.toUpperCase()} - ${card.set.name}`"
                    :title="`${card.set.code.toUpperCase()} - ${card.set.name}`"
                    v-tooltip="{
                        content: `${card.set.code.toUpperCase()} - ${card.set.name}`,
                        container: tooltipContainer ?? false
                    }"
                />
            </span>
            <span v-if="card.artist" class="face-image__panel-artist">
                <icon name="brush" :size="0" />
                {{ card.artist }}
            </span>
        </div>
    </div>
</template>

<style lang="scss">
// doesn't work scoped.
@use "Abstracts/mixins" as m;

@include m.theme-dark(".face-image__set") {
    filter: none;
}
</style>
