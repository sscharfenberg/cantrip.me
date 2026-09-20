<template>
    <h1>
        <span class="line">cantrip.me</span>
    </h1>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/colors" as c;
@use "Abstracts/shadows" as sh;

h1 {
    display: flex;
    position: relative;
    flex-direction: column;

    padding: 0;
    margin: 0;

    font-weight: normal;
    line-height: 1;
    letter-spacing: 0.1em;

    @include m.mqset("font-size", 1rem, 1.3rem, 1.6rem, 1.8rem);

    @include m.mq("desktop") {
        flex-direction: row;

        gap: 0.5ch;
    }

    .line {
        margin-left: calc((sibling-count() - sibling-index()) * map.get(s.$components, "header", "step-margin"));
        transform: skew(-15deg);

        color: map.get(c.$components, "header", "title-first-letter");

        font-weight: 900;
        text-shadow: map.get(sh.$header, "title-first-letter");

        @include m.mqset("font-size", 1.2rem, 1.6rem, 2.1rem, 2.5rem);
    }
}
</style>
