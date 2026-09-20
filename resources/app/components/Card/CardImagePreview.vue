<script setup lang="ts">
import { nextTick, ref } from "vue";
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
     * Where to teleport the floating preview. Defaults to `body`. Inside a
     * native `<dialog>` opened with `showModal()` pass a node within the
     * dialog (`#modal-body`): everything outside an open modal dialog is
     * inert, and keeping the preview in the dialog's own subtree keeps it
     * out of that. Escaping the modal's *clipping* is a separate matter,
     * handled by the popover promotion below.
     */
    teleportTo?: string;
}>();
const emit = defineEmits<{ preview: [] }>();
const visible = ref(false);
/** The floating preview element, once `visible` has rendered it. */
const previewRef = ref<HTMLElement | null>(null);
const x = ref(0);
const y = ref(0);
let timeout: ReturnType<typeof setTimeout> | null = null;
/** Respect the user's reduced-motion preference by disabling the tooltip entirely. */
const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
/**
 * Start the show timer; captures the initial mouse position for the tooltip.
 *
 * Once rendered, the preview is promoted into the top layer as a popover.
 * That is what lets it escape a modal: `Modal.vue`'s content box sets
 * `overflow: hidden` and its body scrolls, so a plain positioned child is
 * clipped to the modal — and the dialog's own top-layer promotion paints it
 * above anything left in the normal stacking context. A top-layer element is
 * clipped by nothing and sits above the dialog. `manual` rather than `auto`
 * so light-dismiss and Escape keep belonging to the modal, not to a preview
 * that closes on mouseleave anyway.
 */
function onMouseEnter(e: MouseEvent) {
    if (prefersReducedMotion || visible.value) return;
    timeout = setTimeout(async () => {
        positionTooltip(e);
        visible.value = true;
        await nextTick();
        previewRef.value?.showPopover();
    }, SHOW_DELAY);
}
/** Reposition the tooltip as the mouse moves within the trigger element. */
function onMouseMove(e: MouseEvent) {
    if (visible.value) positionTooltip(e);
}
/**
 * Cancel pending show timer and hide the tooltip immediately.
 *
 * Dropping `visible` unmounts the element, which removes it from the top
 * layer on its own — no `hidePopover()` call, which would throw on an
 * element that never got shown.
 */
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
        <div
            v-if="visible"
            ref="previewRef"
            popover="manual"
            class="card-preview"
            :style="{ left: x + 'px', top: y + 'px' }"
        >
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
    inset: auto;
    z-index: 10000;

    // Undo the `[popover]` UA styles. They centre the element with
    // `inset: 0; margin: auto` and draw an opaque bordered box with its own
    // scrollbar — we position from the cursor and want no chrome at all.
    // The inline `left` / `top` bindings beat `inset: auto` for those two.
    overflow: visible;
    width: fit-content;
    height: fit-content;
    padding: 0;
    border: 0;
    margin: 0;

    background: transparent;

    pointer-events: none;

    &__image {
        display: block;

        width: 240px;

        border-radius: 10px;

        box-shadow: map.get(sh.$main, "card-preview");
    }
}
</style>
