<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Stats from "Components/UI/Stats/Stats.vue";
import StatsDonut, { type StatsDonutSegment } from "Components/UI/Stats/StatsDonut.vue";
import StatsItem from "Components/UI/Stats/StatsItem.vue";
import { useFormatting } from "Composables/useFormatting.ts";

/**
 * Sentinel slice key used by StatsService when the long tail of a
 * distribution collapses into a single bundled bucket. Mirrors
 * `StatsService::OTHER_KEY` on the backend.
 */
const OTHER_KEY = "other";

const props = defineProps<{
    /**
     * Aggregate stats over every deck the user owns (active + archived).
     * Shipped by DecksController::list via StatsService::forUserDecks.
     */
    stats: {
        totalDecks: number;
        totalWorth: number;
        avgWorth: number;
        medianWorth: number;
        formats: Record<string, number>;
        states: Record<string, number>;
        modes: Record<string, number>;
        /** Color presence across the user's decks (WUBRG). */
        colors: Record<"W" | "U" | "B" | "R" | "G", number>;
    };
}>();

/**
 * Map each WUBRG letter to its global `$stats` palette slot so the
 * color-identity donut renders in actual mana colors regardless of
 * slice ordering. Mirrors the slot numbering in
 * `_global-color-tokens.scss`.
 */
const COLOR_SLOT: Record<"W" | "U" | "B" | "R" | "G", number> = {
    W: 6,
    B: 7,
    U: 8,
    R: 9,
    G: 10
};
/** Canonical WUBRG ordering for the legend. */
const COLOR_ORDER: ReadonlyArray<"W" | "U" | "B" | "R" | "G"> = ["W", "U", "B", "R", "G"];

const { t } = useI18n();
const { formatDecimals, formatPrice } = useFormatting();

/** Sort segments by count desc so the biggest slice anchors 12 o'clock. */
const sortedEntries = (record: Record<string, number>): Array<[string, number]> =>
    Object.entries(record)
        .filter(([, count]) => count > 0)
        .sort(([, a], [, b]) => b - a);

/**
 * Resolve the legend label for a slice. The bundled "other" slice
 * always maps to the generic `pages.decks.stats.other` translation —
 * its base namespace is irrelevant.
 */
const labelFor = (key: string, baseNamespace: string): string =>
    key === OTHER_KEY ? t("pages.decks.stats.other") : t(`${baseNamespace}.${key}`);

const formatSegments = computed<StatsDonutSegment[]>(() =>
    sortedEntries(props.stats.formats).map(([format, count]) => ({
        key: format,
        label: labelFor(format, "enums.card_formats"),
        count
    }))
);

const stateSegments = computed<StatsDonutSegment[]>(() =>
    sortedEntries(props.stats.states).map(([state, count]) => ({
        key: state,
        label: labelFor(state, "enums.deck_state"),
        count
    }))
);

const modeSegments = computed<StatsDonutSegment[]>(() =>
    sortedEntries(props.stats.modes).map(([mode, count]) => ({
        key: mode,
        label: mode === OTHER_KEY ? t("pages.decks.stats.other") : t(`pages.deck.collection_mode.modes.${mode}.label`),
        count,
        tooltip: mode === OTHER_KEY ? undefined : t(`pages.deck.collection_mode.modes.${mode}.description`)
    }))
);

/**
 * Color identity segments in canonical WUBRG order. Each segment pins
 * its own color slot so the visual order around the donut stays stable
 * even when one color dominates — and the slice for U is always blue,
 * R is always red, etc.
 */
const colorSegments = computed<StatsDonutSegment[]>(() =>
    COLOR_ORDER.filter(color => props.stats.colors[color] > 0).map(color => ({
        key: color,
        label: t(`pages.deck.stats.colors.color.${color}`),
        count: props.stats.colors[color],
        colorSlot: COLOR_SLOT[color]
    }))
);
</script>

<template>
    <stats>
        <stats-donut :title="$t('pages.decks.stats.formats.title')" :segments="formatSegments" />
        <stats-donut :title="$t('pages.decks.stats.colors.title')" :segments="colorSegments" />
        <stats-donut :title="$t('pages.decks.stats.states.title')" :segments="stateSegments" />
        <stats-item>
            <template #title>{{ $t("pages.decks.stats.totalDecks.title") }}</template>
            <template #value>{{ formatDecimals(stats.totalDecks) }}</template>
            <template #explanation>{{ $t("pages.decks.stats.totalDecks.explanation") }}</template>
        </stats-item>
        <stats-item>
            <template #title>{{ $t("pages.decks.stats.totalWorth.title") }}</template>
            <template #value>{{ formatPrice(stats.totalWorth) }}</template>
            <template #explanation>{{ $t("pages.decks.stats.totalWorth.explanation") }}</template>
        </stats-item>
        <stats-item>
            <template #title>{{ $t("pages.decks.stats.avgWorth.title") }}</template>
            <template #value>{{ formatPrice(stats.avgWorth) }}</template>
            <template #detail>
                {{ $t("pages.decks.stats.avgWorth.median", { value: formatPrice(stats.medianWorth) }) }}
            </template>
            <template #explanation>{{ $t("pages.decks.stats.avgWorth.explanation") }}</template>
        </stats-item>

        <stats-donut :title="$t('pages.decks.stats.modes.title')" :segments="modeSegments" />
    </stats>
</template>

<style scoped lang="scss">
.stats {
    margin-bottom: 1lh;
}
</style>
