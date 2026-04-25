<template>
    <h3><slot /></h3>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

h3 {
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
