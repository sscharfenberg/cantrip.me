<script setup lang="ts">
withDefaults(
    defineProps<{
        /** Heading level — 4 where the groups sit under a level-3 heading of their own. */
        level?: 3 | 4;
    }>(),
    {
        level: 3
    }
);
</script>

<template>
    <component :is="`h${level}`" class="group-headline"><slot /></component>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.group-headline {
    padding: map.get(s.$pages, "deck", "group", "headline", "padding");
    border: map.get(s.$pages, "deck", "group", "headline", "border") solid
        map.get(c.$pages, "deck", "group", "headline", "border");
    margin: 0 0 map.get(s.$pages, "deck", "group", "gap") 0;

    background-color: map.get(c.$pages, "deck", "group", "headline", "background");
    color: map.get(c.$pages, "deck", "group", "headline", "surface");
    border-radius: map.get(s.$pages, "deck", "group", "headline", "radius");

    font-size: map.get(s.$pages, "deck", "group", "headline", "font");
    font-weight: 400;
}
</style>
