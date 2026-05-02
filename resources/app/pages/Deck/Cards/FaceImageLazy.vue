<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import Icon from "Components/UI/Icon.vue";
/** @emits preview — Fired when the front/back face image is clicked. */
const emit = defineEmits<{ preview: [] }>();
defineProps<{
    /** Front face image URL. */
    cardImage0: string | null;
    /** Back face image URL — when set, renders the flip button. */
    cardImage1: string | null;
    /** Alt text for both img tags. */
    name: string;
}>();
/** Root element — observed to defer image loading until the card scrolls into view. */
const rootEl = ref<HTMLElement | null>(null);
/** True after the card has first entered the viewport; unblocks the `<img>` render. */
const loaded = ref(false);
/** True when the back face is showing. */
const flipped = ref(false);
/** True while the flip animation is running (prevents rapid double-clicks). */
const animating = ref(false);
/**
 * Toggle between the front and back face. Guarded by `animating` so rapid
 * clicks during the CSS flip transition don't stack state changes.
 */
function onFlip(): void {
    if (animating.value) return;
    animating.value = true;
    flipped.value = !flipped.value;
}
let observer: IntersectionObserver | null = null;
onMounted(() => {
    if (!rootEl.value) return;
    observer = new IntersectionObserver(entries => {
        if (entries[0]?.isIntersecting) {
            loaded.value = true;
            observer?.disconnect();
        }
    });
    observer.observe(rootEl.value);
});
onBeforeUnmount(() => {
    observer?.disconnect();
});
</script>

<template>
    <li
        ref="rootEl"
        class="face-image"
        :class="{ 'face-image--flipped': flipped }"
        @transitionend="animating = false"
    >
        <img
            v-if="loaded && cardImage0"
            :src="cardImage0"
            :alt="name"
            loading="lazy"
            class="face-image__front"
            @click="emit('preview')"
        />
        <img
            v-if="loaded && cardImage1"
            :src="cardImage1"
            :alt="name"
            loading="lazy"
            class="face-image__back"
            @click="emit('preview')"
        />
        <button v-if="cardImage1" type="button" class="face-image__flip" @click.stop="onFlip">
            <icon name="flip" />
        </button>
        <slot />
    </li>
</template>

<style lang="scss" scoped>
// FaceImageLazy is always click-to-preview (img click emits `preview`).
// CardFaceImage shares the same .face-image__front/__back classes but
// has no built-in click handler, so the cursor lives here, not in the
// shared component partial.
:deep(.face-image__front),
:deep(.face-image__back) {
    cursor: pointer;
}
</style>