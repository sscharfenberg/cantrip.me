<template>
    <h1>
        <span class="line">cantrip.me</span>
        <span class="status" v-tooltip="$t('header.status')">- beta -</span>
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

    .status {
        position: absolute;
        top: 0;
        right: 1rem;

        padding: 0.125rem 0.5rem;

        transform: rotate(-10deg);

        background-color: map.get(c.$components, "header", "status", "background");
        color: map.get(c.$components, "header", "status", "surface");

        font-size: 1rem;
        text-transform: lowercase;
        letter-spacing: -0.03em;

        cursor: help;
    }
}
</style>
