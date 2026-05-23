<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Stats from "Components/UI/Stats/Stats.vue";
import StatsDonut, { type StatsDonutSegment } from "Components/UI/Stats/StatsDonut.vue";
import StatsItem from "Components/UI/Stats/StatsItem.vue";
import { useFormatting } from "Composables/useFormatting.ts";
/**
 * Mirrors `StatsService::OTHER_KEY` — the bundled tail-slice sentinel
 * when a distribution has more than ten distinct values.
 */
const OTHER_KEY = "other";
const props = defineProps<{
    /**
     * Site-wide deck stats payload. Same shape as the per-user
     * decks-page payload, just aggregated across every user's decks.
     * Shipped by WelcomeController::show via StatsService::forSiteDecks.
     */
    stats: {
        totalDecks: number;
        totalWorth: number;
        avgWorth: number;
        medianWorth: number;
        formats: Record<string, number>;
        states: Record<string, number>;
        modes: Record<string, number>;
        colors: Record<"W" | "U" | "B" | "R" | "G", number>;
    };
}>();
/** WUBRG → global $stats palette slot (mirrors _global-color-tokens.scss). */
const COLOR_SLOT: Record<"W" | "U" | "B" | "R" | "G", number> = {
    W: 6,
    B: 7,
    U: 8,
    R: 9,
    G: 10
};
const COLOR_ORDER: ReadonlyArray<"W" | "U" | "B" | "R" | "G"> = ["W", "U", "B", "R", "G"];
const { t } = useI18n();
const { formatDecimals, formatPrice } = useFormatting();
const sortedEntries = (record: Record<string, number>): Array<[string, number]> =>
    Object.entries(record)
        .filter(([, count]) => count > 0)
        .sort(([, a], [, b]) => b - a);
const labelFor = (key: string, baseNamespace: string): string =>
    key === OTHER_KEY ? t("pages.welcome.decks_stats.other") : t(`${baseNamespace}.${key}`);
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
        label:
            mode === OTHER_KEY
                ? t("pages.welcome.decks_stats.other")
                : t(`pages.deck.collection_mode.modes.${mode}.label`),
        count,
        tooltip: mode === OTHER_KEY ? undefined : t(`pages.deck.collection_mode.modes.${mode}.description`)
    }))
);
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
        <stats-item>
            <template #title>{{ $t("pages.welcome.decks_stats.totalDecks.title") }}</template>
            <template #value>{{ formatDecimals(stats.totalDecks) }}</template>
            <template #explanation>{{ $t("pages.welcome.decks_stats.totalDecks.explanation") }}</template>
        </stats-item>
        <stats-donut :title="$t('pages.welcome.decks_stats.formats.title')" :segments="formatSegments" />
        <stats-donut :title="$t('pages.welcome.decks_stats.colors.title')" :segments="colorSegments" />
        <stats-item>
            <template #title>{{ $t("pages.welcome.decks_stats.totalWorth.title") }}</template>
            <template #value>{{ formatPrice(stats.totalWorth) }}</template>
            <template #explanation>{{ $t("pages.welcome.decks_stats.totalWorth.explanation") }}</template>
        </stats-item>
        <stats-donut :title="$t('pages.welcome.decks_stats.states.title')" :segments="stateSegments" />
        <stats-item>
            <template #title>{{ $t("pages.welcome.decks_stats.avgWorth.title") }}</template>
            <template #value>{{ formatPrice(stats.avgWorth) }}</template>
            <template #detail>
                {{ $t("pages.welcome.decks_stats.avgWorth.median", { value: formatPrice(stats.medianWorth) }) }}
            </template>
            <template #explanation>{{ $t("pages.welcome.decks_stats.avgWorth.explanation") }}</template>
        </stats-item>
        <stats-donut :title="$t('pages.welcome.decks_stats.modes.title')" :segments="modeSegments" />
    </stats>
</template>
