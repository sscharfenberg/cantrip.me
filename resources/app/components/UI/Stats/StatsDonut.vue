<script setup lang="ts">
import { computed } from "vue";
import { annulusPath, arcPath } from "@/utils/donutPath";
import Headline from "Components/UI/Headline.vue";
import { useFormatting } from "Composables/useFormatting.ts";

const { formatDecimals } = useFormatting();

export interface StatsDonutSegment {
    /** Stable key for v-for. */
    key: string;
    /** Pre-translated legend label. */
    label: string;
    /** Raw value — drives slice size and renders in the legend. */
    count: number;
    /**
     * Pre-translated tooltip body (HTML allowed). Defaults to `label`
     * when not provided. Mounted via FloatingVue's `html: true` setting
     * configured globally in main.ts.
     */
    tooltip?: string;
    /**
     * Pin this segment to a specific color slot (1..10). When unset,
     * the segment cycles through slots by position. Use this for
     * donuts where the color carries semantic meaning — e.g. a WUBRG
     * color-identity donut maps each color to its mana slot (W→6,
     * B→7, U→8, R→9, G→10).
     */
    colorSlot?: number;
}

const props = withDefaults(
    defineProps<{
        /** Tile title (translated). */
        title: string;
        /** Donut segments, in display order. Order also picks the color slot. */
        segments: StatsDonutSegment[];
        /**
         * Suppress the per-slice percentage text in the legend, the
         * tooltip aria-label, and the legend bar's percent suffix.
         * Use this when the donut intentionally covers only a subset
         * of the universe (e.g. "top 5 sets"), so a local
         * share-of-shown number wouldn't represent share-of-total.
         * Bar geometry still reflects local share — kept for visual
         * comparison within the tile.
         */
        hidePercent?: boolean;
    }>(),
    { hidePercent: false }
);

/**
 * Number of distinct color slots the SCSS block defines. Segments past
 * the tenth wrap back to slot 1 — keeps the palette finite without
 * silently dropping data when a future caller passes more.
 */
const COLOR_SLOTS = 10;

/**
 * Geometry, in viewBox units (`viewBox="-100 -100 200 200"`). Mirrors
 * the outer ring of the deck-view color donut so the two visualisations
 * read as part of the same family. 4 units of margin around the outer
 * radius keep the 2-unit stroke from clipping at the viewBox edges.
 */
const R_OUTER = 96;
const R_INNER = 60;
/**
 * Radial cut between adjacent segments, in viewBox units. The 2-unit
 * stroke is centered on each edge, so half eats into the gap; the
 * remainder is the visible clear gap between strokes. Larger than the
 * deck-view color donut's `4` because this component renders smaller
 * by default — matching `4` here would look much tighter in absolute
 * pixels.
 */
const SEGMENT_GAP = 8;
const TWO_PI = Math.PI * 2;
const HALF_PI = Math.PI / 2;

interface ResolvedSegment extends StatsDonutSegment {
    colorIdx: number;
    percent: number;
    path: string;
}

const total = computed(() => props.segments.reduce((sum, s) => sum + s.count, 0));

const resolved = computed<ResolvedSegment[]>(() => {
    if (total.value === 0) return [];

    const positive = props.segments
        .map((seg, idx) => ({ seg, colorIdx: seg.colorSlot ?? (idx % COLOR_SLOTS) + 1 }))
        .filter(entry => entry.seg.count > 0);

    if (positive.length === 1) {
        const { seg, colorIdx } = positive[0];
        return [{ ...seg, colorIdx, percent: 100, path: annulusPath(R_OUTER, R_INNER) }];
    }

    const halfGapOuter = Math.asin(SEGMENT_GAP / 2 / R_OUTER);
    const halfGapInner = Math.asin(SEGMENT_GAP / 2 / R_INNER);
    let theta0 = -HALF_PI;
    const out: ResolvedSegment[] = [];
    for (const { seg, colorIdx } of positive) {
        const share = seg.count / total.value;
        const theta1 = theta0 + share * TWO_PI;
        out.push({
            ...seg,
            colorIdx,
            percent: share * 100,
            path: arcPath(
                theta0 + halfGapOuter,
                theta1 - halfGapOuter,
                theta0 + halfGapInner,
                theta1 - halfGapInner,
                R_OUTER,
                R_INNER
            )
        });
        theta0 = theta1;
    }
    return out;
});
</script>

<template>
    <li class="stats-donut">
        <headline :size="4">{{ title }}</headline>
        <div class="stats-donut__layout">
            <svg
                v-if="resolved.length"
                class="stats-donut__svg"
                viewBox="-100 -100 200 200"
                role="img"
                :aria-label="title"
            >
                <path
                    v-for="seg in resolved"
                    :key="seg.key"
                    v-tooltip="{ content: seg.tooltip || seg.label, html: true }"
                    class="stats-donut__segment"
                    :class="`stats-donut__segment--${seg.colorIdx}`"
                    :d="seg.path"
                    fill-rule="evenodd"
                    :aria-label="
                        hidePercent
                            ? `${seg.label}: ${seg.count}`
                            : `${seg.label}: ${seg.count} (${Math.round(seg.percent)}%)`
                    "
                />
            </svg>
            <ul v-if="resolved.length" class="stats-donut__legend">
                <li v-for="seg in resolved" :key="seg.key" class="stats-donut__legend-item">
                    <span
                        class="stats-donut__swatch"
                        :class="`stats-donut__swatch--${seg.colorIdx}`"
                        aria-hidden="true"
                    />
                    <div class="stats-donut__legend-body">
                        <div class="stats-donut__legend-text">
                            <span class="stats-donut__legend-label">{{ seg.label }}</span>
                            <span class="stats-donut__legend-count">
                                {{ formatDecimals(seg.count)
                                }}<template v-if="!hidePercent">&nbsp;({{ Math.round(seg.percent) }}%)</template>
                            </span>
                        </div>
                        <div class="stats-donut__legend-bar" aria-hidden="true">
                            <div
                                class="stats-donut__legend-bar-fill"
                                :class="`stats-donut__legend-bar-fill--${seg.colorIdx}`"
                                :style="{ width: `${seg.percent}%` }"
                            />
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </li>
</template>

<style scoped lang="scss">
@use "sass:color";
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

// Ten color slots sourced from $components.stats-donut. Slots 1..5 are
// the non-mana default palette; 6..10 hold WUBRG and only get reached
// when a donut has six or more slices.
$slot-ids: ("1", "2", "3", "4", "5", "6", "7", "8", "9", "10");

.stats-donut {
    display: flex;
    flex-direction: column;

    // Span two grid columns in the parent <Stats> auto-fit grid so the
    // donut and its legend can sit side-by-side. Only applied from the
    // `portrait` breakpoint up — on phone widths the grid auto-fits to
    // a single column anyway, and `span 2` there would force the
    // browser to create a second implicit column, halving the
    // available width for every tile in the section.
    @include m.mq("portrait") {
        grid-column: span 2;
    }

    padding: map.get(s.$components, "stats", "padding");
    border: map.get(s.$components, "stats", "border") solid map.get(c.$components, "stats", "border");

    background-color: map.get(c.$components, "stats", "background");
    color: map.get(c.$components, "stats", "surface");
    border-radius: map.get(s.$components, "stats", "radius");

    &__layout {
        display: flex;
        align-items: flex-start;
        flex-wrap: wrap;

        margin-top: 0.5rem;
        gap: 1rem;
    }

    &__svg {
        display: block;

        width: auto;
        height: 8rem;
        flex: none;

        aspect-ratio: 1;
    }

    &__segment {
        stroke-width: 2;
        stroke-linejoin: round;

        @each $idx in $slot-ids {
            &--#{$idx} {
                fill: map.get(c.$components, "stats-donut", $idx, "background");
                stroke: map.get(c.$components, "stats-donut", $idx, "border");
            }
        }
    }

    &__legend {
        display: flex;
        flex-direction: column;

        flex: 1 1 8rem;
        padding: 0;
        margin: 0;
        gap: 0.25rem;

        list-style: none;

        font-size: 0.9rem;
        font-variant-numeric: tabular-nums;
    }

    &__legend-item {
        display: grid;
        align-items: start;
        grid-template-columns: auto 1fr;

        gap: 0.5rem;
    }

    &__legend-body {
        display: flex;
        flex-direction: column;

        min-width: 0;
        gap: 0.15rem;
    }

    &__legend-text {
        display: flex;
        align-items: baseline;
        justify-content: space-between;

        min-width: 0;
        gap: 0.5rem;
    }

    &__legend-label {
        overflow: hidden;
        min-width: 0;

        white-space: nowrap;
        text-overflow: ellipsis;
    }

    &__legend-count {
        opacity: 0.7;
    }

    &__legend-bar {
        overflow: hidden;

        width: 100%;
        height: 0.25rem;

        background-color: light-dark(color.adjust(#000, $alpha: -0.9), color.adjust(#fff, $alpha: -0.9));
        border-radius: 999px;
    }

    &__legend-bar-fill {
        height: 100%;

        border-radius: inherit;

        transition: width 0.3s ease;

        @each $idx in $slot-ids {
            &--#{$idx} {
                background-color: map.get(c.$components, "stats-donut", $idx, "background");
            }
        }

        @media (prefers-reduced-motion: reduce) {
            transition: none;
        }
    }

    // Swatch sits on the first grid row only — center it on the text
    // baseline so the bar below feels visually anchored to the text.
    &__swatch {
        display: inline-block;
        align-self: center;

        width: 0.75rem;
        height: 0.75rem;
        border: 1px solid transparent;
        margin-top: 0.2rem;

        border-radius: 50%;

        @each $idx in $slot-ids {
            &--#{$idx} {
                background-color: map.get(c.$components, "stats-donut", $idx, "background");
                border-color: map.get(c.$components, "stats-donut", $idx, "border");
            }
        }
    }
}
</style>
