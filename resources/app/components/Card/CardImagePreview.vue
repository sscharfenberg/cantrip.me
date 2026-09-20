<script setup lang="ts">
import { ref } from "vue";
/** Tooltip image width in pixels, used for viewport boundary clamping. */
const IMAGE_WIDTH = 240;
/** Approximate tooltip image height in pixels, used for viewport boundary clamping. */
const IMAGE_HEIGHT = 340;
/** Horizontal offset from the cursor in pixels. */
const OFFSET_X = 16;
/** Vertical offset from the cursor in pixels. */
const OFFSET_Y = 16;
/** Delay in milliseconds before the tooltip appears. */
const SHOW_DELAY = 300;
const props = defineProps<{
    /** URL of the card image to display. When null, the tooltip is disabled. */
    src: string | null;
    /** Alt text for the card image. */
    alt: string;
    /**
     * Where to teleport the floating preview. Defaults to `body`, which is
     * right everywhere except inside a native `<dialog>` opened with
     * `showModal()`: that renders in the browser's top layer, which paints
     * above every z-index in the normal stacking context, so a preview left
     * in `body` would hide behind the modal. Pass `#modal-body` there.
     */
    teleportTo?: string;
}>();
const emit = defineEmits<{ preview: [] }>();
const visible = ref(false);
const x = ref(0);
const y = ref(0);
let timeout: ReturnType<typeof setTimeout> | null = null;
/** Respect the user's reduced-motion preference by disabling the tooltip entirely. */
const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
/** Start the show timer; captures the initial mouse position for the tooltip. */
function onMouseEnter(e: MouseEvent) {
    if (prefersReducedMotion) return;
    timeout = setTimeout(() => {
        positionTooltip(e);
        visible.value = true;
    }, SHOW_DELAY);
}
/** Reposition the tooltip as the mouse moves within the trigger element. */
function onMouseMove(e: MouseEvent) {
    if (visible.value) positionTooltip(e);
}
/** Cancel pending show timer and hide the tooltip immediately. */
function onMouseLeave() {
    if (timeout) clearTimeout(timeout);
    visible.value = false;
}
/**
 * Calculate tooltip position from cursor coordinates.
 * Flips horizontally when the image would overflow the right viewport edge.
 * Clamps vertically so the image stays fully visible.
 */
function positionTooltip(e: MouseEvent) {
    let px = e.clientX + OFFSET_X;
    let py = e.clientY + OFFSET_Y;
    if (px + IMAGE_WIDTH > window.innerWidth) px = e.clientX - IMAGE_WIDTH - OFFSET_X;
    if (py + IMAGE_HEIGHT > window.innerHeight) py = window.innerHeight - IMAGE_HEIGHT;
    x.value = px;
    y.value = Math.max(0, py);
}
</script>

<template>
    <span
        v-if="src"
        class="card-preview__trigger"
        @mouseenter="onMouseEnter"
        @mousemove="onMouseMove"
        @mouseleave="onMouseLeave"
        @click="emit('preview')"
    >
        <slot />
    </span>
    <span v-else class="card-preview__trigger" @click="emit('preview')">
        <slot />
    </span>
    <Teleport :to="props.teleportTo ?? 'body'">
        <div v-if="visible" class="card-preview" :style="{ left: x + 'px', top: y + 'px' }">
            <img :src="src!" :alt="alt" class="card-preview__image" />
        </div>
    </Teleport>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/shadows" as sh;
@use "Abstracts/sizes" as s;

.card-preview__trigger {
    display: block;

    padding: map.get(s.$components, "datatable", "padding", "td");

    cursor: pointer;
}

.card-preview {
    position: fixed;
    z-index: 10000;

    pointer-events: none;

    &__image {
        display: block;

        width: 240px;

        border-radius: 10px;

        box-shadow: map.get(sh.$main, "card-preview");
    }
}
</style>
