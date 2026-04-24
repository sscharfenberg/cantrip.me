<script setup lang="ts">
import { computed, ref, watch, nextTick, onBeforeUnmount } from "vue";
const props = defineProps<{
    /** The active row whose actions are shown, or null when the popover is closed. */
    row: unknown | null;
    /** The three-dot button element that triggered the popover, used for CSS anchor positioning. */
    triggerEl: HTMLElement | null;
}>();
const emit = defineEmits<{
    /** Emitted when the popover closes (light dismiss, Escape, or action taken). */
    close: [];
}>();
/** Reference to the native popover element for programmatic show/hide. */
const popoverRef = ref<HTMLElement | null>(null);

/** Derive the CSS anchor name from the trigger button's inline style. */
const anchorName = computed(() => {
    if (!props.triggerEl) return "";
    return props.triggerEl.style.getPropertyValue("anchor-name") || "";
});
/** Open popover when a row becomes active (three-dot button clicked in Body/Cards). */
watch(
    () => props.row,
    async newRow => {
        if (newRow && popoverRef.value) {
            await nextTick();
            popoverRef.value.showPopover();
        }
    }
);
/** Emit close when the native popover dismisses (click outside, light dismiss). */
function onToggle(event: Event) {
    const toggleEvent = event as ToggleEvent;
    if (toggleEvent.newState === "closed") {
        emit("close");
    }
}
/** Dismiss the popover when Escape is pressed (keyboard accessibility). */
function onKeydown(event: KeyboardEvent) {
    if (event.key === "Escape") {
        popoverRef.value?.hidePopover();
    }
}
/** Clean up if the component unmounts while the popover is still open. */
onBeforeUnmount(() => {
    if (popoverRef.value?.matches(":popover-open")) {
        popoverRef.value.hidePopover();
    }
});
</script>

<template>
    <div
        ref="popoverRef"
        popover
        class="popover-content dt-actions"
        :style="{ 'position-anchor': anchorName }"
        :aria-label="$t('components.datatable.row_actions')"
        @toggle="onToggle"
        @keydown="onKeydown"
    >
        <ul class="popover-list">
            <slot />
        </ul>
    </div>
</template>

<style lang="scss" scoped>
.dt-actions {
    /* Anchor to right edge, popover extends leftward — avoids right-side overflow */
    inset: anchor(bottom) anchor(right) auto auto;
}
</style>
