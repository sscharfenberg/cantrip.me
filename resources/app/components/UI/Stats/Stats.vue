<script setup lang="ts">
import { computed } from "vue";

const props = defineProps<{
    /**
     * Optional BEM modifier suffix applied to the `<ul>` root as
     * `stats--<variant>`. Consumers can use it to style children
     * uniformly (e.g. `> :nth-child(even/odd)` for striped tile
     * backgrounds) regardless of whether each child is a `StatsItem`
     * or a `StatsDonut` — both render as plain `<li>` children of
     * this list.
     */
    variant?: string;
}>();

const variantClass = computed(() => (props.variant ? `stats--${props.variant}` : null));
</script>

<template>
    <ul class="stats" :class="variantClass">
        <slot />
    </ul>
</template>

<style lang="scss" scoped>
@use "sass:color";
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));

    // Mosaic packing: when a 2-col StatsDonut leaves a 1-col hole at
    // the end of a row (e.g. 5-column layouts), later 1-col tiles
    // backfill that hole instead of pushing past it. Visual reading
    // order may differ slightly from DOM order — acceptable for stats
    // tiles where ordering isn't load-bearing.
    grid-auto-flow: dense;

    padding: 0;
    margin: 0;
    gap: 0.75rem;

    list-style: none;

    &--on-accordion > :deep(li) {
        background-color: map.get(c.$components, "stats", "background-accordion");
    }
}
</style>
