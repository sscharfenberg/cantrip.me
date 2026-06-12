<script setup lang="ts">
withDefaults(
    defineProps<{
        size?: 2 | 3 | 4;
        anchorId?: string;
    }>(),
    {
        size: 2
    }
);
</script>

<template>
    <h2 v-if="size === 2" :id="anchorId">
        <slot />
        <span v-if="$slots.right" class="right"><slot name="right" /></span>
    </h2>
    <h3 v-if="size === 3" :id="anchorId">
        <slot />
        <span v-if="$slots.right" class="right"><slot name="right" /></span>
    </h3>
    <h4 v-if="size === 4" :id="anchorId">
        <slot />
        <span v-if="$slots.right" class="right"><slot name="right" /></span>
    </h4>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/sizes" as s;
@use "Abstracts/typography" as t;
@use "Abstracts/colors" as c;

h2,
h3,
h4 {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    margin: 0 0 0.5lh;
    gap: 0.5ch;
}

h2 {
    border-bottom: map.get(s.$components, "headline", "h2", "border") solid
        map.get(c.$components, "headline", "h2", "border");

    color: map.get(c.$components, "headline", "h2", "surface");

    font-family: map.get(t.$main, "h2");
    font-size: map.get(s.$components, "headline", "h2", "font");
    font-weight: 200;
}

h3 {
    border-bottom: map.get(s.$components, "headline", "h3", "border") solid
        map.get(c.$components, "headline", "h3", "border");

    color: map.get(c.$components, "headline", "h3", "surface");

    font-family: map.get(t.$main, "h3");
    font-size: map.get(s.$components, "headline", "h3", "font");
    font-weight: 200;
}

h4 {
    border-bottom: map.get(s.$components, "headline", "h4", "border") solid
        map.get(c.$components, "headline", "h4", "border");

    color: map.get(c.$components, "headline", "h4", "surface");

    font-family: map.get(t.$main, "h4");
    font-size: map.get(s.$components, "headline", "h4", "font");
    font-weight: 200;
}

.right {
    margin-left: auto;
}

.right :slotted(img) {
    vertical-align: -0.15em;
}
</style>
