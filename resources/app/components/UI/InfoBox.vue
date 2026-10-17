<script setup lang="ts">
/******************************************************************************
 * A boxed note with an info icon, for text that explains the content
 * beside it — how a chart is counted, where a number comes from.
 *
 * Unlike `Paragraph`, the body is a slot rather than a single `<p>`, so
 * it can hold several paragraphs; consecutive children are spaced by the
 * box. Font size and width are left to the consumer.
 *****************************************************************************/
import Icon from "Components/UI/Icon.vue";
</script>

<template>
    <div class="info-box" role="note">
        <icon name="info" :size="1" class="info-box__icon" aria-hidden="true" />
        <div class="info-box__body"><slot /></div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.info-box {
    display: flex;
    align-items: flex-start;

    padding: map.get(s.$components, "info-box", "padding");
    border: map.get(s.$components, "info-box", "border") solid map.get(c.$components, "info-box", "border");
    gap: map.get(s.$components, "info-box", "gap");

    background-color: map.get(c.$components, "info-box", "background");
    color: map.get(c.$components, "info-box", "surface");
    border-radius: map.get(s.$components, "info-box", "radius");

    &__icon {
        color: map.get(c.$components, "info-box", "icon");
    }

    &__body {
        display: flex;
        flex-direction: column;

        min-width: 0;
        gap: 0.5lh;

        > :deep(*) {
            margin: 0;
        }
    }
}
</style>
