<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Headline from "Components/UI/Headline.vue";
import type { ManaCurveBucket } from "Composables/useDeckStats.ts";
/**
 * @emits select — Fired with the clicked bucket's `cmc` (0..8, where 8
 * means "8 or more"). Re-clicking the same column emits `null` to
 * clear the filter; the parent decides what "selected" means downstream.
 */
const emit = defineEmits<{ select: [cmc: number | null] }>();
const props = defineProps<{
    /** 9 buckets, cmc 0..8+ in order. */
    curve: ManaCurveBucket[];
    /** Average mana value across non-land cards (lands excluded, commanders included, quantity-weighted). */
    average: number;
}>();
const { t, locale } = useI18n();
/** Locale-aware 2-decimal formatter for the average mana value (e.g. "2.45" / "2,45"). */
const formattedAverage = computed(() =>
    props.average.toLocaleString(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
);
/** Currently-clicked bucket's `cmc`, or `null` when nothing is selected. */
const selectedCmc = ref<number | null>(null);
/**
 * Toggle: clicking the active column clears the selection, clicking a
 * different column moves it. Either way the new value is emitted so the
 * parent can mirror it onto the deck card views.
 */
const onColumnClick = (cmc: number): void => {
    selectedCmc.value = selectedCmc.value === cmc ? null : cmc;
    emit("select", selectedCmc.value);
};
/** Tallest bucket — divisor for relative heights. Floored at 1 so empty decks don't divide by zero. */
const max = computed(() => Math.max(1, ...props.curve.map(b => b.total)));
/** Convert a bucket value into its height percentage against the tallest bucket. */
const pct = (value: number): number => (value / max.value) * 100;
/** "0".."7" for exact CMC, "8+" for the overflow bucket. */
const cmcLabel = (cmc: number): string => (cmc === 8 ? "8+" : String(cmc));
/**
 * Per-bar text alternative — the visible chart is decorative
 * (`aria-hidden`) and screen readers only see this label.
 */
const ariaFor = (bucket: ManaCurveBucket): string =>
    t("pages.deck.stats.curve.bar_aria", {
        cmc: cmcLabel(bucket.cmc),
        total: bucket.total,
        permanents: bucket.permanents,
        spells: bucket.spells
    });
/**
 * Return the bucket's two segments in render order — bigger one first
 * so it lands at the bottom of the visual stack (column-reverse), with
 * the smaller stacked on top. Permanents wins ties.
 */
const segmentsFor = (bucket: ManaCurveBucket): { kind: "permanents" | "spells"; value: number }[] =>
    bucket.permanents >= bucket.spells
        ? [
              { kind: "permanents", value: bucket.permanents },
              { kind: "spells", value: bucket.spells }
          ]
        : [
              { kind: "spells", value: bucket.spells },
              { kind: "permanents", value: bucket.permanents }
          ];
</script>

<template>
    <figure class="mana-curve">
        <headline :size="4">{{ t("pages.deck.stats.curve.title") }}</headline>
        <div class="mana-curve__avg">
            <i18n-t keypath="pages.deck.stats.curve.average" scope="global">
                <template #avg>
                    <span class="mana-curve__avg-value">{{ formattedAverage }}</span>
                </template>
            </i18n-t>
        </div>
        <ol class="mana-curve__bars">
            <li v-for="bucket in curve" :key="bucket.cmc" class="mana-curve__bar">
                <span
                    class="mana-curve__count"
                    :class="{ 'mana-curve__count--zero': bucket.total === 0 }"
                    aria-hidden="true"
                    >{{ bucket.total }}</span
                >
                <button
                    type="button"
                    class="mana-curve__column"
                    :class="{ 'mana-curve__column--selected': selectedCmc === bucket.cmc }"
                    :aria-label="ariaFor(bucket)"
                    :aria-pressed="selectedCmc === bucket.cmc"
                    @click="onColumnClick(bucket.cmc)"
                >
                    <template v-for="seg in segmentsFor(bucket)" :key="seg.kind">
                        <div
                            v-if="seg.value > 0"
                            class="mana-curve__segment"
                            :class="`mana-curve__segment--${seg.kind}`"
                            :style="{ height: `${pct(seg.value)}%` }"
                        >
                            {{ seg.value > 3 ? seg.value : null }}
                        </div>
                    </template>
                </button>
                <span class="mana-curve__label" aria-hidden="true">{{ cmcLabel(bucket.cmc) }}</span>
            </li>
        </ol>
        <ul class="mana-curve__legend" aria-hidden="true">
            <li class="mana-curve__legend-item">
                <span class="mana-curve__swatch mana-curve__swatch--permanents"></span>
                {{ t("pages.deck.stats.curve.permanents") }}
            </li>
            <li class="mana-curve__legend-item">
                <span class="mana-curve__swatch mana-curve__swatch--spells"></span>
                {{ t("pages.deck.stats.curve.spells") }}
            </li>
        </ul>
    </figure>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.mana-curve {
    display: flex;
    flex-direction: column;

    margin: 0;
    gap: map.get(s.$components, "mana-curve", "gap");

    &__avg {
        margin: 0 0 0.5lh;
    }

    &__avg-value {
        padding: map.get(s.$components, "mana-curve", "avg-padding");
        border: map.get(s.$components, "mana-curve", "border") solid map.get(s.$components, "mana-curve", "avg-border");

        background-color: map.get(c.$components, "mana-curve", "avg", "background");
        color: map.get(c.$components, "mana-curve", "avg", "surface");
        border-radius: map.get(s.$components, "mana-curve", "radius");
    }

    &__bars {
        display: grid;
        align-items: end;
        grid-template-columns: repeat(9, minmax(0, 1fr));

        height: map.get(s.$components, "mana-curve", "height");
        padding: 0;
        margin: 0;
        gap: map.get(s.$components, "mana-curve", "gap");

        list-style: none;
    }

    &__bar {
        display: grid;
        grid-template-rows: auto 1fr auto;

        height: 100%;
        gap: #{map.get(s.$components, "mana-curve", "gap") * 0.5};

        text-align: center;
    }

    &__count {
        align-self: end;

        line-height: 1;
        font-variant-numeric: tabular-nums;

        &--zero {
            opacity: 0.4;
        }
    }

    // The visual bar — a flex column-reverse so segments stack from the
    // bottom up. Each segment's `height: X%` is its share of the tallest
    // bucket, so segment heights together equal `bucket.total / max`.
    &__column {
        display: flex;
        flex-direction: column-reverse;

        height: 100%;
        border: 0;

        background: transparent;

        cursor: pointer;

        &--selected .mana-curve__segment--permanents,
        &--selected .mana-curve__segment--spells {
            background: map.get(c.$components, "mana-curve", "selected", "background");
            color: map.get(c.$components, "mana-curve", "selected", "surface");
            border-color: map.get(c.$components, "mana-curve", "selected", "border");
        }
    }

    &__segment {
        display: flex;
        align-items: center;
        justify-content: center;

        width: 100%;
        border: map.get(s.$components, "mana-curve", "border") solid transparent;

        transition: height 200ms ease;

        @media (prefers-reduced-motion: reduce) {
            transition: none;
        }

        // column-reverse: first DOM child sits at the bottom of the
        // visual stack, last DOM child at the top. Round the actual
        // visible top + bottom of the bar regardless of which segment
        // happens to be present (one, the other, or both — `:only-child`
        // matches both selectors so a single-segment bar gets all four
        // corners).
        &:last-child {
            border-top-left-radius: map.get(s.$components, "mana-curve", "radius");
            border-top-right-radius: map.get(s.$components, "mana-curve", "radius");
        }

        &:first-child {
            border-bottom-right-radius: map.get(s.$components, "mana-curve", "radius");
            border-bottom-left-radius: map.get(s.$components, "mana-curve", "radius");
        }

        &--permanents {
            background: map.get(c.$components, "mana-curve", "permanents", "background");
            color: map.get(c.$components, "mana-curve", "permanents", "surface");
            border-color: map.get(c.$components, "mana-curve", "permanents", "border");
        }

        &--spells {
            background: map.get(c.$components, "mana-curve", "spells", "background");
            color: map.get(c.$components, "mana-curve", "spells", "surface");
            border-color: map.get(c.$components, "mana-curve", "spells", "border");
        }
    }

    &__label {
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }

    &__legend {
        display: flex;
        flex-wrap: wrap;

        padding: 0;
        margin: 0;
        gap: 1rem;

        list-style: none;
    }

    &__legend-item {
        display: flex;
        align-items: center;

        gap: map.get(s.$components, "mana-curve", "gap");
    }

    &__swatch {
        display: inline-block;

        width: map.get(s.$components, "mana-curve", "swatch");
        height: map.get(s.$components, "mana-curve", "swatch");
        border: map.get(s.$components, "mana-curve", "border") solid transparent;

        border-radius: map.get(s.$components, "mana-curve", "radius");

        &--permanents {
            background: map.get(c.$components, "mana-curve", "permanents", "background");
            color: map.get(c.$components, "mana-curve", "permanents", "surface");
            border-color: map.get(c.$components, "mana-curve", "permanents", "surface");
        }

        &--spells {
            background: map.get(c.$components, "mana-curve", "spells", "background");
            color: map.get(c.$components, "mana-curve", "spells", "surface");
            border-color: map.get(c.$components, "mana-curve", "spells", "surface");
        }
    }
}
</style>
