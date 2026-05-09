<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { karstenArticleUrl } from "@/utils/frankKarstenAnalysis";
import Headline from "Components/UI/Headline.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import { useDeckHighlight, type HighlightColor } from "Composables/useDeckHighlight.ts";
import type { ColorPipTally, KarstenColorAnalysis, KarstenCombinedAnalysis } from "Composables/useDeckStats.ts";

const props = defineProps<{
    /** WUBRG pip totals across all non-land card costs (deck + commanders + companion). */
    cost: ColorPipTally;
    /** WUBRG totals from `produced_mana × quantity`. */
    production: ColorPipTally;
    /** Per-color Karsten source recommendation for the deck's format. */
    karsten: KarstenColorAnalysis[];
    /**
     * Karsten gold-card "combined" rows — one per unique color combo
     * demanded by a gold card in the deck. Empty for monocolor decks
     * or decks without gold cards.
     */
    karstenCombined: KarstenCombinedAnalysis[];
    /** Deck format slug — drives which Karsten article the attribution links to. */
    format: string;
}>();

const { t } = useI18n();
const { selectedColorConsumption, selectedColorProduction, setColorConsumption, setColorProduction } =
    useDeckHighlight();

const COLORS: readonly HighlightColor[] = ["W", "U", "B", "R", "G"] as const;

/**
 * Karsten article URL the attribution links to, derived from the deck
 * format (so a Commander deck and a Modern deck can deep-link to
 * different editions of the analysis if they exist).
 */
const karstenUrl = computed(() => karstenArticleUrl(props.format));

/**
 * Geometry, in viewBox units. The SVG is `viewBox="-100 -100 200 200"`,
 * so 1 unit ≈ 1px when the figure renders at 12rem (192px) — close
 * enough that the visual stroke / gap thickness lines up with what the
 * design asked for. 4 units of margin around the outer ring make sure
 * the 2px stroke isn't clipped at the viewBox edges.
 */
const R_OUTER_OUT = 96;
const R_OUTER_IN = 66;
const R_INNER_OUT = 56;
const R_INNER_IN = 30;
/**
 * Radial cut between adjacent segments, in viewBox units. The 2-unit
 * stroke is centered on each segment's edge, so half of it (1 unit per
 * side) eats into the gap; SEGMENT_GAP = 4 leaves a visible 2-unit
 * clear gap between strokes — matching what the design asked for.
 */
const SEGMENT_GAP = 4;
/**
 * Corner radius for each segment's four corners, in viewBox units.
 * Each corner is replaced with a small tangent arc of this radius so
 * the segment reads as "rounded" rather than as a hard-edged keystone.
 * Auto-clamped per-segment so very thin slices fall back to sharp
 * corners instead of overlapping themselves.
 */
const CORNER_RADIUS = 5;
const TWO_PI = Math.PI * 2;
const HALF_PI = Math.PI / 2;

interface Segment {
    color: HighlightColor;
    count: number;
    percent: number;
    path: string;
}

/**
 * Build the annulus path for a one-color (100%) ring as two concentric
 * circles in a single `<path>` with `fill-rule="evenodd"` — no segment
 * gap is needed, and the entire annulus is one clickable target.
 */
const annulusPath = (rOuter: number, rInner: number): string =>
    `M${-rOuter},0 A${rOuter},${rOuter} 0 1 0 ${rOuter},0 A${rOuter},${rOuter} 0 1 0 ${-rOuter},0 Z` +
    ` M${-rInner},0 A${rInner},${rInner} 0 1 1 ${rInner},0 A${rInner},${rInner} 0 1 1 ${-rInner},0 Z`;

/**
 * Build a single arc segment between two angles, with separate half-gap
 * offsets at the outer and inner radii (so the visual gap between
 * neighbouring segments stays radial rather than narrowing toward the
 * center) and `CORNER_RADIUS`-sized tangent arcs replacing each of the
 * four corners (so the keystone reads as rounded rather than sharp).
 *
 * The corner approximation treats the radial sides as a 90° meeting of
 * the (locally tangent) ring and the (locally radial) line — slightly
 * inexact when the gap is non-zero, but the SVG renderer fits a smooth
 * curve through the two endpoints regardless and the visual error is
 * sub-pixel.
 */
const arcPath = (
    theta0Outer: number,
    theta1Outer: number,
    theta0Inner: number,
    theta1Inner: number,
    rOuter: number,
    rInner: number
): string => {
    const sweepOuter = theta1Outer - theta0Outer;
    const sweepInner = theta1Inner - theta0Inner;
    /**
     * Cap corner radius so two adjacent corners can't overlap each
     * other on the same arc, and so the inner+outer pair can't overlap
     * across the radial extent of the ring.
     */
    const cr = Math.min(
        CORNER_RADIUS,
        (sweepOuter * rOuter) / 2,
        (sweepInner * rInner) / 2,
        (rOuter - rInner) / 2
    );

    if (cr < 0.5) {
        const x1 = rOuter * Math.cos(theta0Outer);
        const y1 = rOuter * Math.sin(theta0Outer);
        const x2 = rOuter * Math.cos(theta1Outer);
        const y2 = rOuter * Math.sin(theta1Outer);
        const x3 = rInner * Math.cos(theta1Inner);
        const y3 = rInner * Math.sin(theta1Inner);
        const x4 = rInner * Math.cos(theta0Inner);
        const y4 = rInner * Math.sin(theta0Inner);
        const largeArc = sweepOuter > Math.PI ? 1 : 0;
        return `M${x1},${y1} A${rOuter},${rOuter} 0 ${largeArc} 1 ${x2},${y2} L${x3},${y3} A${rInner},${rInner} 0 ${largeArc} 0 ${x4},${y4} Z`;
    }

    const dOuter = cr / rOuter;
    const dInner = cr / rInner;

    const aArcX = rOuter * Math.cos(theta0Outer + dOuter);
    const aArcY = rOuter * Math.sin(theta0Outer + dOuter);
    const aLineX = (rOuter - cr) * Math.cos(theta0Outer);
    const aLineY = (rOuter - cr) * Math.sin(theta0Outer);
    const bArcX = rOuter * Math.cos(theta1Outer - dOuter);
    const bArcY = rOuter * Math.sin(theta1Outer - dOuter);
    const bLineX = (rOuter - cr) * Math.cos(theta1Outer);
    const bLineY = (rOuter - cr) * Math.sin(theta1Outer);
    const cLineX = (rInner + cr) * Math.cos(theta1Inner);
    const cLineY = (rInner + cr) * Math.sin(theta1Inner);
    const cArcX = rInner * Math.cos(theta1Inner - dInner);
    const cArcY = rInner * Math.sin(theta1Inner - dInner);
    const dArcX = rInner * Math.cos(theta0Inner + dInner);
    const dArcY = rInner * Math.sin(theta0Inner + dInner);
    const dLineX = (rInner + cr) * Math.cos(theta0Inner);
    const dLineY = (rInner + cr) * Math.sin(theta0Inner);

    const largeArcOuter = sweepOuter - 2 * dOuter > Math.PI ? 1 : 0;
    const largeArcInner = sweepInner - 2 * dInner > Math.PI ? 1 : 0;

    return (
        `M${aArcX},${aArcY}` +
        ` A${rOuter},${rOuter} 0 ${largeArcOuter} 1 ${bArcX},${bArcY}` +
        ` A${cr},${cr} 0 0 1 ${bLineX},${bLineY}` +
        ` L${cLineX},${cLineY}` +
        ` A${cr},${cr} 0 0 1 ${cArcX},${cArcY}` +
        ` A${rInner},${rInner} 0 ${largeArcInner} 0 ${dArcX},${dArcY}` +
        ` A${cr},${cr} 0 0 1 ${dLineX},${dLineY}` +
        ` L${aLineX},${aLineY}` +
        ` A${cr},${cr} 0 0 1 ${aArcX},${aArcY} Z`
    );
};

/**
 * Project a WUBRG tally onto a ring. WUBRG is laid out clockwise from
 * 12 o'clock (start angle = -π/2, sweep angle increases clockwise in
 * SVG's flipped y-axis). Empty colors are skipped — they take up no
 * angular share. A single-color ring renders as a full annulus.
 */
const buildSegments = (tally: ColorPipTally, rOuter: number, rInner: number): Segment[] => {
    const total = COLORS.reduce((sum, c) => sum + tally[c], 0);
    if (total === 0) return [];

    const present = COLORS.filter(c => tally[c] > 0);
    if (present.length === 1) {
        const color = present[0];
        return [{ color, count: tally[color], percent: 100, path: annulusPath(rOuter, rInner) }];
    }

    const halfGapOuter = Math.asin(SEGMENT_GAP / 2 / rOuter);
    const halfGapInner = Math.asin(SEGMENT_GAP / 2 / rInner);
    let theta0 = -HALF_PI;
    const segments: Segment[] = [];
    for (const color of present) {
        const share = tally[color] / total;
        const theta1 = theta0 + share * TWO_PI;
        segments.push({
            color,
            count: tally[color],
            percent: share * 100,
            path: arcPath(
                theta0 + halfGapOuter,
                theta1 - halfGapOuter,
                theta0 + halfGapInner,
                theta1 - halfGapInner,
                rOuter,
                rInner
            )
        });
        theta0 = theta1;
    }
    return segments;
};

const outerSegments = computed(() => buildSegments(props.cost, R_OUTER_OUT, R_OUTER_IN));
const innerSegments = computed(() => buildSegments(props.production, R_INNER_OUT, R_INNER_IN));

interface DistributionRow {
    color: HighlightColor;
    count: number;
    percent: number;
}

/**
 * Per-color breakdown for the text list under each ring. Mirrors the
 * donut: same colors, same percentages, same exclusion of zero-pip
 * entries — readers can pair the row to the slice without translating
 * angles back to numbers.
 */
const buildRows = (tally: ColorPipTally): DistributionRow[] => {
    const total = COLORS.reduce((sum, c) => sum + tally[c], 0);
    if (total === 0) return [];
    return COLORS.filter(c => tally[c] > 0).map(color => ({
        color,
        count: tally[color],
        percent: (tally[color] / total) * 100
    }));
};

const costRows = computed(() => buildRows(props.cost));
const productionRows = computed(() => buildRows(props.production));

const hoveredOuter = ref<HighlightColor | null>(null);
const hoveredInner = ref<HighlightColor | null>(null);

/**
 * Mutual exclusion across the two rings — picking an outer (cost)
 * segment clears any inner (production) selection and vice versa.
 * Very few cards both produce *and* consume a colored mana, so AND-ing
 * the two axes mostly returns empty sets; treat the rings as one radio
 * group split across cost/production.
 */
const onOuterClick = (color: HighlightColor): void => {
    if (selectedColorConsumption.value === color) {
        setColorConsumption(null);
        return;
    }
    setColorConsumption(color);
    setColorProduction(null);
};
/**
 * Inner-ring (production) click handler — mirror of `onOuterClick`.
 * Toggles selection on the production color and clears any current
 * cost selection so the two rings act as one radio group split across
 * cost / production. See `onOuterClick`'s comment for the full rule.
 */
const onInnerClick = (color: HighlightColor): void => {
    if (selectedColorProduction.value === color) {
        setColorProduction(null);
        return;
    }
    setColorProduction(color);
    setColorConsumption(null);
};

/** Round a 0–100 fraction to a whole-number string for aria + UI display. */
const formatPercent = (n: number): string => `${Math.round(n)}`;

/** Aria label for an outer-ring (cost) segment — color name + count + percent. */
const ariaForCost = (seg: Segment): string =>
    t("pages.deck.stats.colors.cost_aria", {
        color: t(`pages.deck.stats.colors.color.${seg.color}`),
        count: seg.count,
        percent: formatPercent(seg.percent)
    });

/** Aria label for an inner-ring (production) segment — same shape as `ariaForCost`. */
const ariaForProduction = (seg: Segment): string =>
    t("pages.deck.stats.colors.production_aria", {
        color: t(`pages.deck.stats.colors.color.${seg.color}`),
        count: seg.count,
        percent: formatPercent(seg.percent)
    });
</script>

<template>
    <figure class="color-donut">
        <headline :size="4">{{ t("pages.deck.stats.colors.title") }}</headline>
        <div class="color-donut__layout">
            <svg
                class="color-donut__svg"
                viewBox="-100 -100 200 200"
                role="img"
                :aria-label="t('pages.deck.stats.colors.title')"
            >
                <g
                    class="color-donut__ring color-donut__ring--outer"
                    :class="{ 'color-donut__ring--hovered': hoveredOuter !== null }"
                >
                    <path
                        v-for="seg in outerSegments"
                        :key="`outer-${seg.color}`"
                        class="color-donut__segment"
                        :class="[
                            `color-donut__segment--${seg.color}`,
                            {
                                'color-donut__segment--selected': selectedColorConsumption === seg.color,
                                'color-donut__segment--active': hoveredOuter === seg.color
                            }
                        ]"
                        :d="seg.path"
                        fill-rule="evenodd"
                        role="button"
                        tabindex="0"
                        :aria-label="ariaForCost(seg)"
                        :aria-pressed="selectedColorConsumption === seg.color"
                        @click="onOuterClick(seg.color)"
                        @keydown.enter.prevent="onOuterClick(seg.color)"
                        @keydown.space.prevent="onOuterClick(seg.color)"
                        @mouseenter="hoveredOuter = seg.color"
                        @mouseleave="hoveredOuter = null"
                        @focus="hoveredOuter = seg.color"
                        @blur="hoveredOuter = null"
                    />
                </g>
                <g
                    class="color-donut__ring color-donut__ring--inner"
                    :class="{ 'color-donut__ring--hovered': hoveredInner !== null }"
                >
                    <path
                        v-for="seg in innerSegments"
                        :key="`inner-${seg.color}`"
                        class="color-donut__segment"
                        :class="[
                            `color-donut__segment--${seg.color}`,
                            {
                                'color-donut__segment--selected': selectedColorProduction === seg.color,
                                'color-donut__segment--active': hoveredInner === seg.color
                            }
                        ]"
                        :d="seg.path"
                        fill-rule="evenodd"
                        role="button"
                        tabindex="0"
                        :aria-label="ariaForProduction(seg)"
                        :aria-pressed="selectedColorProduction === seg.color"
                        @click="onInnerClick(seg.color)"
                        @keydown.enter.prevent="onInnerClick(seg.color)"
                        @keydown.space.prevent="onInnerClick(seg.color)"
                        @mouseenter="hoveredInner = seg.color"
                        @mouseleave="hoveredInner = null"
                        @focus="hoveredInner = seg.color"
                        @blur="hoveredInner = null"
                    />
                </g>
            </svg>
            <div class="color-donut__text">
                <section class="color-donut__section">
                    <h5 class="color-donut__heading">{{ t("pages.deck.stats.colors.outer") }}</h5>
                    <ul class="color-donut__rows">
                        <li v-for="row in costRows" :key="row.color">
                            <i18n-t keypath="pages.deck.stats.colors.row" scope="global">
                                <template #color>
                                    <img
                                        :src="`/symbol/${row.color}.svg`"
                                        :alt="t(`pages.deck.stats.colors.color.${row.color}`)"
                                        class="color-donut__symbol"
                                    />
                                </template>
                                <template #count>{{ row.count }}</template>
                                <template #percent>{{ Math.round(row.percent) }}</template>
                            </i18n-t>
                        </li>
                    </ul>
                </section>
                <section class="color-donut__section">
                    <h5 class="color-donut__heading">{{ t("pages.deck.stats.colors.inner") }}</h5>
                    <ul class="color-donut__rows">
                        <li v-for="row in productionRows" :key="row.color">
                            <i18n-t keypath="pages.deck.stats.colors.row" scope="global">
                                <template #color>
                                    <img
                                        :src="`/symbol/${row.color}.svg`"
                                        :alt="t(`pages.deck.stats.colors.color.${row.color}`)"
                                        class="color-donut__symbol"
                                    />
                                </template>
                                <template #count>{{ row.count }}</template>
                                <template #percent>{{ Math.round(row.percent) }}</template>
                            </i18n-t>
                        </li>
                    </ul>
                </section>
            </div>
            <section v-if="karsten.length" class="color-donut__section">
                <h5 class="color-donut__heading">{{ t("pages.deck.stats.colors.karsten.title") }}</h5>
                <ul class="color-donut__rows">
                    <li
                        v-for="row in karsten"
                        :key="row.color"
                        :class="{ 'color-donut__rows-item--short': row.short > 0 }"
                    >
                        <i18n-t
                            :keypath="
                                row.short > 0
                                    ? 'pages.deck.stats.colors.karsten.short'
                                    : 'pages.deck.stats.colors.karsten.sufficient'
                            "
                            scope="global"
                        >
                            <template #color>
                                <img
                                    :src="`/symbol/${row.color}.svg`"
                                    :alt="t(`pages.deck.stats.colors.color.${row.color}`)"
                                    class="color-donut__symbol"
                                />
                            </template>
                            <template #have>{{ row.have }}</template>
                            <template #need>{{ row.need }}</template>
                            <template #short>{{ row.short }}</template>
                        </i18n-t>
                    </li>
                </ul>
                <template v-if="karstenCombined.length">
                    <h6 class="color-donut__subheading">
                        {{ t("pages.deck.stats.colors.karsten.combined_title") }}
                    </h6>
                    <ul class="color-donut__rows">
                        <li
                            v-for="row in karstenCombined"
                            :key="row.colors.join('')"
                            :class="{ 'color-donut__rows-item--short': row.short > 0 }"
                        >
                            <i18n-t
                                :keypath="
                                    row.short > 0
                                        ? 'pages.deck.stats.colors.karsten.combined_short'
                                        : 'pages.deck.stats.colors.karsten.combined_sufficient'
                                "
                                scope="global"
                            >
                                <template #colors>
                                    <span class="color-donut__combined-colors">
                                        <img
                                            v-for="c in row.colors"
                                            :key="c"
                                            :src="`/symbol/${c}.svg`"
                                            :alt="t(`pages.deck.stats.colors.color.${c}`)"
                                            class="color-donut__symbol"
                                        />
                                    </span>
                                </template>
                                <template #have>{{ row.have }}</template>
                                <template #need>{{ row.need }}</template>
                                <template #short>{{ row.short }}</template>
                            </i18n-t>
                        </li>
                    </ul>
                </template>
                <p class="color-donut__attribution">
                    <i18n-t keypath="pages.deck.stats.colors.karsten.attribution" scope="global" tag="span">
                        <template #link>
                            <labelled-link :href="karstenUrl">{{
                                t("pages.deck.stats.colors.karsten.attribution_link")
                            }}</labelled-link>
                        </template>
                    </i18n-t>
                </p>
            </section>
        </div>
    </figure>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.color-donut {
    display: flex;
    flex-direction: column;

    margin: 0;

    // Two-column grid on mobile (donut + text legend in row 1, Karsten
    // section spans the full width of row 2), three columns at
    // landscape+. The grid children, in DOM order, are: the SVG donut,
    // the text block (cost + production legend stacked), and the
    // Karsten recommendation section. `auto 1fr 1fr` keeps the donut at
    // its intrinsic 12rem square and lets the two text columns share
    // the remaining width.
    &__layout {
        display: grid;
        align-items: start;
        grid-template-columns: auto 1fr;

        gap: map.get(s.$components, "color-donut", "gap-mobile");

        > .color-donut__section {
            grid-column: 1 / -1;
        }

        @include m.mq("landscape") {
            grid-template-columns: auto 1fr 1fr;

            gap: map.get(s.$components, "color-donut", "gap");

            > .color-donut__section {
                grid-column: auto;
            }
        }
    }

    &__svg {
        display: block;

        width: auto;
        height: map.get(s.$components, "color-donut", "height-mobile");
        flex: none;

        aspect-ratio: 1;

        @include m.mq("landscape") {
            height: map.get(s.$components, "color-donut", "height");
        }
    }

    // Cost + production legend, stacked vertically — sits in the
    // middle grid column at portrait+ and as the second row on mobile.
    &__text {
        display: flex;
        flex-direction: column;

        gap: map.get(s.$components, "color-donut", "gap");
    }

    &__segment {
        stroke-width: 2;
        stroke-linejoin: round;

        cursor: pointer;

        transition:
            opacity map.get(ti.$timings, "fast") ease,
            stroke-width map.get(ti.$timings, "fast") ease;

        &--W {
            fill: map.get(c.$components, "color-donut", "mana", "W", "fill");
            stroke: map.get(c.$components, "color-donut", "mana", "W", "stroke");
        }

        &--U {
            fill: map.get(c.$components, "color-donut", "mana", "U", "fill");
            stroke: map.get(c.$components, "color-donut", "mana", "U", "stroke");
        }

        &--B {
            fill: map.get(c.$components, "color-donut", "mana", "B", "fill");
            stroke: map.get(c.$components, "color-donut", "mana", "B", "stroke");
        }

        &--R {
            fill: map.get(c.$components, "color-donut", "mana", "R", "fill");
            stroke: map.get(c.$components, "color-donut", "mana", "R", "stroke");
        }

        &--G {
            fill: map.get(c.$components, "color-donut", "mana", "G", "fill");
            stroke: map.get(c.$components, "color-donut", "mana", "G", "stroke");
        }

        &--selected {
            stroke: map.get(c.$components, "color-donut", "selected-stroke");
            stroke-width: 3;
        }

        &:focus {
            outline: none;
        }

        &:focus-visible {
            stroke: map.get(c.$components, "color-donut", "selected-stroke");
            stroke-width: 3;
        }

        @media (prefers-reduced-motion: reduce) {
            transition: none;
        }
    }

    // Within-ring dim: when any segment in this ring is hovered, fade
    // every other segment in the same ring. Cross-ring is intentionally
    // unaffected — each ring answers its own question (cost vs.
    // production) and the rings should be readable independently.
    &__ring--hovered .color-donut__segment:not(.color-donut__segment--active) {
        opacity: 0.3;
    }

    &__section {
        display: flex;
        flex-direction: column;

        gap: map.get(s.$components, "color-donut", "gap");
    }

    &__heading {
        margin: 0;

        font-size: 1rem;
        font-weight: 400;
    }

    &__subheading {
        opacity: 0.8;

        margin: #{map.get(s.$components, "color-donut", "gap") * 0.5} 0 0;

        font-size: 0.9rem;
        font-weight: 400;
    }

    &__combined-colors {
        display: inline;

        margin-right: 0.25em;

        // Defeat the per-symbol right margin so multiple symbols sit
        // tight together — only the trailing one needs spacing from the
        // " {have} sources" text.
        & .color-donut__symbol {
            margin-right: 0;
        }
    }

    &__rows {
        display: flex;
        flex-direction: column;

        padding: 0;
        margin: 0;
        gap: #{map.get(s.$components, "color-donut", "gap") * 0.5};

        list-style: none;

        font-size: 0.9em;
        font-variant-numeric: tabular-nums;
    }

    &__symbol {
        width: 1em;
        height: 1em;
        margin-right: 0.25em;

        vertical-align: -0.15em;
    }

    &__rows-item--short {
        opacity: 1;

        font-weight: 600;
    }

    &__attribution {
        margin: 0;

        font-size: smaller;
    }
}
</style>
