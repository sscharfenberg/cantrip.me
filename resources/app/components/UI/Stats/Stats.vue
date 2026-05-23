<template>
    <ul class="stats">
        <slot />
    </ul>
</template>

<style lang="scss" scoped>
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
}
</style>
