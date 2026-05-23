<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Stats from "Components/UI/Stats/Stats.vue";
import StatsDonut, { type StatsDonutSegment } from "Components/UI/Stats/StatsDonut.vue";
import StatsItem from "Components/UI/Stats/StatsItem.vue";
import { useFormatting } from "Composables/useFormatting.ts";

/**
 * Mirrors `StatsService::OTHER_KEY` — used by the collapser when a
 * distribution has more than ten distinct values. Container types
 * cap at 8 today so this branch is unreachable for now; included for
 * symmetry with the other welcome-page donuts.
 */
const OTHER_KEY = "other";

const props = defineProps<{
    /**
     * Site-wide collection stats. Shipped by WelcomeController::show
     * via StatsService::forSiteCollection.
     */
    stats: {
        totalCards: number;
        uniqueCards: number;
        containers: number;
        totalPrice: number;
        containerTypes: Record<string, number>;
        rarities: Record<"common" | "uncommon" | "rare" | "mythic", number>;
        topSets: Array<{ code: string; name: string; count: number }>;
        mostValuableCard: {
            name: string;
            price: number;
            printingsOwned: number;
        } | null;
        mostOwnedCard: {
            name: string;
            owned: number;
            printingsOwned: number;
        } | null;
    };
}>();

const { t } = useI18n();
const { formatDecimals, formatPrice } = useFormatting();

const sortedEntries = (record: Record<string, number>): Array<[string, number]> =>
    Object.entries(record)
        .filter(([, count]) => count > 0)
        .sort(([, a], [, b]) => b - a);

const containerTypeSegments = computed<StatsDonutSegment[]>(() =>
    sortedEntries(props.stats.containerTypes).map(([type, count]) => ({
        key: type,
        label: type === OTHER_KEY ? t("pages.welcome.site_stats.other") : t(`enums.container_type.${type}`),
        count
    }))
);

const raritySegments = computed<StatsDonutSegment[]>(() =>
    sortedEntries(props.stats.rarities).map(([rarity, count]) => ({
        key: rarity,
        label: t(`pages.welcome.site_stats.rarities.${rarity}`),
        count
    }))
);

/**
 * Top-N leaderboard — already sorted server-side, capped at 5, no
 * "Other" sentinel. Map straight onto donut segments without running
 * through `sortedEntries`.
 */
const topSetSegments = computed<StatsDonutSegment[]>(() =>
    props.stats.topSets
        .filter(set => set.count > 0)
        .map(set => ({
            key: set.code,
            label: set.name,
            count: set.count,
            tooltip: `${set.name} (${set.code.toUpperCase()})`
        }))
);
</script>

<template>
    <stats>
        <stats-item v-if="stats.totalPrice > 0">
            <template #title>{{ $t("pages.welcome.site_stats.worth.title") }}</template>
            <template #value>{{ formatPrice(stats.totalPrice) }}</template>
            <template #explanation>{{ $t("pages.welcome.site_stats.worth.explanation") }}</template>
        </stats-item>
        <stats-donut
            v-if="containerTypeSegments.length"
            :title="$t('pages.welcome.site_stats.containerTypes.title')"
            :segments="containerTypeSegments"
        />
        <stats-donut
            v-if="raritySegments.length"
            :title="$t('pages.welcome.site_stats.rarities.title')"
            :segments="raritySegments"
        />
        <stats-item v-if="stats.containers > 0">
            <template #title>{{ $t("pages.welcome.site_stats.containers.title") }}</template>
            <template #value>{{ formatDecimals(stats.containers) }}</template>
            <template #explanation>{{ $t("pages.welcome.site_stats.containers.explanation") }}</template>
        </stats-item>
        <stats-donut
            v-if="topSetSegments.length"
            :title="$t('pages.welcome.site_stats.topSets.title')"
            :segments="topSetSegments"
            hide-percent
        />
        <stats-item v-if="stats.totalCards > 0">
            <template #title>{{ $t("pages.welcome.site_stats.cards.title") }}</template>
            <template #value>{{ formatDecimals(stats.totalCards) }}</template>
            <template #explanation>{{ $t("pages.welcome.site_stats.cards.explanation") }}</template>
        </stats-item>
        <stats-item v-if="stats.uniqueCards > 0">
            <template #title>{{ $t("pages.welcome.site_stats.uniqueCards.title") }}</template>
            <template #value>{{ formatDecimals(stats.uniqueCards) }}</template>
            <template #explanation>{{ $t("pages.welcome.site_stats.uniqueCards.explanation") }}</template>
        </stats-item>
        <stats-item v-if="stats.mostValuableCard">
            <template #title>{{ $t("pages.welcome.site_stats.mostValuableCard.title") }}</template>
            <template #value>{{ stats.mostValuableCard.name }}</template>
            <template #detail>
                {{ formatPrice(stats.mostValuableCard.price) }}
                <span v-if="stats.mostValuableCard.printingsOwned > 1" class="welcome-collection-stats__printings">
                    {{
                        $t("pages.welcome.site_stats.mostValuableCard.printings", {
                            count: stats.mostValuableCard.printingsOwned
                        })
                    }}
                </span>
            </template>
            <template #explanation>{{ $t("pages.welcome.site_stats.mostValuableCard.explanation") }}</template>
        </stats-item>
        <stats-item v-if="stats.mostOwnedCard">
            <template #title>{{ $t("pages.welcome.site_stats.mostOwnedCard.title") }}</template>
            <template #value>{{ stats.mostOwnedCard.name }}</template>
            <template #detail>
                {{ formatDecimals(stats.mostOwnedCard.owned) }}
                <span v-if="stats.mostOwnedCard.printingsOwned > 1" class="welcome-collection-stats__printings">
                    {{
                        $t("pages.welcome.site_stats.mostOwnedCard.printings", {
                            count: stats.mostOwnedCard.printingsOwned
                        })
                    }}
                </span>
            </template>
            <template #explanation>{{ $t("pages.welcome.site_stats.mostOwnedCard.explanation") }}</template>
        </stats-item>
    </stats>
</template>

<style scoped lang="scss">
.welcome-collection-stats__printings {
    opacity: 0.7;
}
</style>
