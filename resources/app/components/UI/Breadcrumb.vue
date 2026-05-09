<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";

const { t } = useI18n();
const { crumbs } = useBreadcrumbs();

/**
 * Resolve a breadcrumb's displayed text: prefer the raw `label` if set,
 * otherwise translate `labelKey` with any supplied interpolation params.
 */
function resolveLabel(crumb: (typeof crumbs.value)[number]): string {
    return crumb.label ?? t(crumb.labelKey ?? "", crumb.params ?? {});
}
</script>

<template>
    <nav v-if="crumbs.length" class="breadcrumb" :aria-label="t('breadcrumb.nav')">
        <Link href="/" class="breadcrumb__item" :aria-label="t('pages.welcome.label')"
            ><span><icon name="home" /></span
        ></Link>
        <template v-for="(crumb, index) in crumbs" :key="crumb.labelKey ?? crumb.label">
            <Link
                v-if="crumb.href"
                :href="crumb.href"
                :class="['breadcrumb__item', { 'breadcrumb__item--parent': index === crumbs.length - 2 }]"
            >
                <span><icon v-if="crumb.icon" :name="crumb.icon" />{{ resolveLabel(crumb) }}</span>
            </Link>
            <span v-else class="breadcrumb__item" aria-current="page">
                <span><icon v-if="crumb.icon" :name="crumb.icon" />{{ resolveLabel(crumb) }}</span>
            </span>
        </template>
    </nav>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

.breadcrumb {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    margin: 0 0 1lh 6px;
    gap: 0.25rem;

    &__item {
        position: relative;

        gap: 1ch;

        color: map.get(c.$components, "breadcrumb", "surface");

        text-decoration: none;

        transition: color map.get(ti.$timings, "fast") linear;

        // use pseudo elements to form an arrow (right side)
        // with the corresponding notch (left side)
        &::before,
        &::after {
            display: inline-block;
            position: absolute;
            left: 0;
            z-index: map.get(z.$index, "cellar");

            width: 100%;
            height: 50%;
            border-right: map.get(s.$components, "breadcrumb", "border") solid
                map.get(c.$components, "breadcrumb", "border");
            border-left: map.get(s.$components, "breadcrumb", "border") solid
                map.get(c.$components, "breadcrumb", "border");

            background-color: map.get(c.$components, "breadcrumb", "background");

            content: "";

            transition: background-color map.get(ti.$timings, "fast") linear;
        }

        &::before {
            top: 0;

            border-top: map.get(s.$components, "breadcrumb", "border") solid
                map.get(c.$components, "breadcrumb", "border");
            transform: skew(30deg);
        }

        &::after {
            bottom: 0;

            border-bottom: map.get(s.$components, "breadcrumb", "border") solid
                map.get(c.$components, "breadcrumb", "border");
            transform: skew(-30deg);
        }

        > span {
            display: flex;
            align-items: center;

            padding: map.get(s.$components, "breadcrumb", "padding");
            gap: 1ch;

            line-height: map.get(s.$components, "breadcrumb", "line-height");
        }

        &:first-child {
            transition: none;

            &::before,
            &::after {
                transition: none;
            }

            > span {
                $border: map.get(s.$components, "breadcrumb", "border");
                $outline: map.get(c.$components, "breadcrumb", "border");

                width: calc(100% - $border);

                margin-left: -6px; // magic number because of skew(30deg).

                background-color: map.get(c.$components, "breadcrumb", "background");
                box-shadow:
                    inset #{$border} 0 0 0 $outline,
                    inset 0 #{$border} 0 0 $outline,
                    inset 0 -#{$border} 0 0 $outline;
            }
        }

        // Skip the last crumb (current page, never a link) AND any
        // non-link crumb — non-owners get a label-only "Decks" chip
        // because /decks is gated to the request user, so a hover affordance
        // would suggest a navigation that won't happen.
        &:not(:last-child, span):hover {
            color: map.get(c.$components, "breadcrumb", "surface-hover");

            &::before,
            &::after {
                background-color: map.get(c.$components, "breadcrumb", "background-hover");
            }
        }

        &:first-child:hover > span {
            background-color: map.get(c.$components, "breadcrumb", "background-hover");
        }

        &:last-child {
            margin-left: 1px;

            color: map.get(c.$components, "breadcrumb", "surface-current");

            &::before,
            &::after {
                background-color: map.get(c.$components, "breadcrumb", "background-current");
            }
        }

        // mobile: show only breadcrumb__item--parent, hide everything else.
        // also, have arrow point in the other direction, since it is a "go back" link
        &:not(.breadcrumb__item--parent) {
            display: none;

            @include m.mq("landscape") {
                display: block;
            }
        }

        &--parent {
            &::before {
                transform: skew(-30deg);
            }

            &::after {
                transform: skew(30deg);
            }

            @include m.mq("landscape") {
                &::before {
                    transform: skew(30deg);
                }

                &::after {
                    transform: skew(-30deg);
                }
            }
        }
    }
}
</style>
