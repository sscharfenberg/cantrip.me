<script setup lang="ts">
withDefaults(
    defineProps<{
        hasFooter?: boolean;
        /** See `Modal`'s prop of the same name. */
        innerScroll?: boolean;
    }>(),
    {
        hasFooter: false,
        innerScroll: false
    }
);
</script>

<template>
    <div
        id="modal-body"
        :class="[
            'modal-dialog__body',
            { 'modal-dialog__body--no-footer': !hasFooter, 'modal-dialog__body--inner-scroll': innerScroll }
        ]"
    >
        <slot />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/sizes" as s;

.modal-dialog__body {
    overflow-y: auto;

    // `min-height: 0` lets this flex item shrink below its intrinsic content
    // size so the parent's `max-height` cap wins and the scrollbar lives here.
    min-height: 0;
    flex: 1 1 auto;

    padding: map.get(s.$components, "modal", "padding");

    // Still `overflow-y: auto`: should the content's fixed part ever outgrow
    // a very short viewport, the body scrolls as a last resort.
    &--inner-scroll {
        display: flex;
        flex-direction: column;
    }

    &--no-footer {
        margin-bottom: calc(
            #{map.get(s.$components, "modal", "radius")} - #{map.get(s.$components, "modal", "border")}
        );
    }
}
</style>
