<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { annulusPath, arcPath } from "@/utils/donutPath";
import Headline from "Components/UI/Headline.vue";
import InfoBox from "Components/UI/InfoBox.vue";
import { useDeckHighlight, type HighlightColor } from "Composables/useDeckHighlight.ts";
import type { ColorPipTally } from "Composables/useDeckStats.ts";

const props = defineProps<{
    /** WUBRG pip totals across all non-land card costs (deck + commanders + companion). */
    cost: ColorPipTally;
    /** Per color, how many cards make it — one pip per copy, unweighted. */
    production: ColorPipTally;
    /**
     * Whether the "Mana sources" section renders beside this one. The
     * explanation only compares the two readings when there is one to
     * compare against.
     */
    sourcesShown: boolean;
}>();

const { t } = useI18n();
const { selectedColorConsumption, selectedColorProduction, setColorConsumption, setColorProduction } =
    useDeckHighlight();

const COLORS: readonly HighlightColor[] = ["W", "U", "B", "R", "G"] as const;

/**
 * Geometry, in viewBox units. The SVG is `viewBox="-100 -100 200 200"`,
 * so 1 unit ≈ 1px when the figure renders at 12rem (192px) — close
 * enough that the visual stroke / gap thickness lines up with what the
 * design asked for. 4 units of margin around the outer ring make sure
 * even the 4px selected stroke isn't clipped at the viewBox edges.
 */
const R_OUTER_OUT = 96;
const R_OUTER_IN = 66;
const R_INNER_OUT = 56;
const R_INNER_IN = 30;
/**
 * Radial cut between adjacent segments, in viewBox units. The 3-unit
 * stroke is centered on each segment's edge, so half of it (1.5 units
 * per side) eats into the gap; SEGMENT_GAP = 5 leaves a visible 2-unit
 * clear gap between strokes — matching what the design asked for.
 */
const SEGMENT_GAP = 5;
const TWO_PI = Math.PI * 2;
const HALF_PI = Math.PI / 2;

interface Segment {
    color: HighlightColor;
    count: number;
    percent: number;
    path: string;
}

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
 * Selects without a mana value: the ring counts every source as one, so
 * every card behind the segment's number highlights, at full strength.
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

/**
 * Mana-symbol image markup for inline use inside HTML tooltip strings.
 * Inlined styling because the FloatingVue tooltip popper renders at
 * `body` level — the component's scoped `.color-donut__symbol` rule
 * would not apply.
 */
const symbolImg = (color: HighlightColor): string => {
    const alt = t(`pages.deck.stats.colors.color.${color}`);
    return `<img src="/symbol/${color}.svg" alt="${alt}" style="width:1em;height:1em;vertical-align:-0.15em;margin:0 0.15em;" />`;
};

/**
 * Tooltip body for an outer-ring (cost) segment — "Cost: {count}
 * {symbol} pips" with the WUBRG symbol rendered inline. FloatingVue is
 * configured with `html: true` (see main.ts) so the returned string is
 * mounted as HTML.
 */
const tooltipForCost = (seg: Segment): string =>
    t("pages.deck.stats.colors.cost_tooltip", {
        count: seg.count,
        symbol: symbolImg(seg.color)
    });

/** Tooltip body for an inner-ring (production) segment — same shape as `tooltipForCost`. */
const tooltipForProduction = (seg: Segment): string =>
    t("pages.deck.stats.colors.production_tooltip", {
        count: seg.count,
        symbol: symbolImg(seg.color)
    });
</script>

<template>
    <div class="color-donut">
        <headline :size="4">{{ t("pages.deck.stats.colors.title") }}</headline>
        <figure class="color-donut__figure">
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
                            v-tooltip="{ content: tooltipForCost(seg), html: true }"
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
                            v-tooltip="{ content: tooltipForProduction(seg), html: true }"
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
                <div class="color-donut__legend">
                    <section class="color-donut__section">
                        <h5 class="color-donut__heading">{{ t("pages.deck.stats.colors.outer") }}</h5>
                        <ul class="color-donut__rows">
                            <li v-for="row in costRows" :key="row.color" class="color-donut__row">
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
                            <li v-for="row in productionRows" :key="row.color" class="color-donut__row">
                                <i18n-t keypath="pages.deck.stats.colors.production_row" scope="global">
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
            </div>
            <figcaption>
                <info-box class="color-donut__explanation">
                    <p class="color-donut__note">{{ t("pages.deck.stats.colors.explanation") }}</p>
                    <p v-if="sourcesShown" class="color-donut__note">
                        {{ t("pages.deck.stats.colors.explanation_sources") }}
                    </p>
                </info-box>
            </figcaption>
        </figure>
    </div>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

// Laid out against the panel's own width, not the viewport: on wide
// screens the deck stats put this section beside "Mana sources", so a
// desktop viewport can still hand it a phone-sized column.
//   - narrow: donut centered above the legend, the two lists stacked;
//   - portrait+: donut beside the legend, the two lists stacked;
//   - landscape+: donut beside the two lists, side by side.
// The explanation always spans the full width underneath.
.color-donut {
    container-type: inline-size;

    // The headline sits outside the figure and brings its own bottom
    // margin, so the gap only spaces the figure's own parts.
    &__figure {
        display: flex;
        flex-direction: column;

        margin: 0;
        gap: map.get(s.$components, "color-donut", "gap");
    }

    &__layout {
        display: grid;

        grid-template-columns: minmax(0, 1fr);
        place-items: center center;

        gap: map.get(s.$components, "color-donut", "gap");

        @include m.cq("portrait") {
            justify-items: start;

            grid-template-columns: auto minmax(0, 1fr);
        }
    }

    &__svg {
        display: block;

        width: auto;
        height: map.get(s.$components, "color-donut", "height-mobile");

        aspect-ratio: 1;

        @include m.cq("landscape") {
            height: map.get(s.$components, "color-donut", "height");
        }
    }

    // Cost + production lists — stacked until there is room for them
    // side by side. `minmax(0, max-content)` keeps each column as wide
    // as its longest row but lets it shrink and wrap rather than
    // overflow the panel.
    &__legend {
        display: grid;
        justify-self: stretch;

        grid-template-columns: minmax(0, 1fr);

        gap: map.get(s.$components, "color-donut", "gap");

        @include m.cq("landscape") {
            grid-template-columns: repeat(2, minmax(0, max-content));

            column-gap: #{map.get(s.$components, "color-donut", "gap") * 2};
        }
    }

    &__segment {
        stroke-width: 3;
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
            stroke-width: 4;
        }

        &:focus {
            outline: none;
        }

        &:focus-visible {
            stroke: map.get(c.$components, "color-donut", "selected-stroke");
            stroke-width: 4;
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

        gap: #{map.get(s.$components, "color-donut", "gap") * 0.5};
    }

    &__heading {
        margin: 0;

        font-size: 1rem;
        font-weight: 400;
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

    // Hanging indent: a row that wraps on a narrow panel continues
    // under its text, not under the mana symbol.
    &__row {
        padding-left: map.get(s.$components, "color-donut", "indent");

        text-indent: calc(-1 * #{map.get(s.$components, "color-donut", "indent")});
    }

    &__symbol {
        width: 1em;
        height: 1em;
        margin-right: 0.25em;

        vertical-align: -0.15em;
    }

    // Applies to the InfoBox root (a child component's root carries this
    // component's scope id). Capped so a full-width panel doesn't stretch
    // the text into unreadable lines.
    &__explanation {
        max-width: map.get(s.$components, "color-donut", "note-width");

        font-size: smaller;
    }
}
</style>
