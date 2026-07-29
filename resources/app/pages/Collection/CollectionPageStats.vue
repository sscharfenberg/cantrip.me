<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Accordion from "Components/UI/Accordion.vue";
import Icon from "Components/UI/Icon.vue";
import Stats from "Components/UI/Stats/Stats.vue";
import StatsDonut, { type StatsDonutSegment } from "Components/UI/Stats/StatsDonut.vue";
import StatsItem from "Components/UI/Stats/StatsItem.vue";
import { useFormatting } from "Composables/useFormatting.ts";
import { usePersistedAccordion } from "Composables/usePersistedAccordion.ts";

const { initialOpen, onToggle } = usePersistedAccordion("collection-page-stats-accordion-open");

/** Mirrors `StatsService::OTHER_KEY`. */
const OTHER_KEY = "other";

const props = defineProps<{
    /**
     * Per-user collection stats. Shipped by CollectionController::list
     * via StatsService::forUserCollection.
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
        label: type === OTHER_KEY ? t("pages.collection.stats.other") : t(`enums.container_type.${type}`),
        count
    }))
);

const raritySegments = computed<StatsDonutSegment[]>(() =>
    sortedEntries(props.stats.rarities).map(([rarity, count]) => ({
        key: rarity,
        label: t(`pages.collection.stats.rarities.${rarity}`),
        count
    }))
);

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
    <accordion class="collection-page-stats" :initial-open="initialOpen" @toggle="onToggle">
        <template #head>
            <span class="collection-page-stats__title">
                <icon name="chart" />
                {{ $t("pages.collection.stats.title") }}
            </span>
        </template>
        <template #body>
            <stats variant="on-accordion">
                <stats-item>
                    <template #title>{{ $t("pages.collection.stats.totalPrice.title") }}</template>
                    <template #value>{{ formatPrice(stats.totalPrice) }}</template>
                    <template #explanation>{{ $t("pages.collection.stats.totalPrice.explanation") }}</template>
                </stats-item>
                <stats-item>
                    <template #title>{{ $t("pages.collection.stats.containers.title") }}</template>
                    <template #value>{{ formatDecimals(stats.containers) }}</template>
                    <template #explanation>{{ $t("pages.collection.stats.containers.explanation") }}</template>
                </stats-item>
                <stats-donut
                    v-if="containerTypeSegments.length"
                    :title="$t('pages.collection.stats.containerTypes.title')"
                    :segments="containerTypeSegments"
                />
                <stats-donut
                    v-if="raritySegments.length"
                    :title="$t('pages.collection.stats.rarities.title')"
                    :segments="raritySegments"
                />
                <stats-item>
                    <template #title>{{ $t("pages.collection.stats.totalCards.title") }}</template>
                    <template #value>{{ formatDecimals(stats.totalCards) }}</template>
                    <template #explanation>{{ $t("pages.collection.stats.totalCards.explanation") }}</template>
                </stats-item>
                <stats-donut
                    v-if="topSetSegments.length"
                    :title="$t('pages.collection.stats.topSets.title')"
                    :segments="topSetSegments"
                    hide-percent
                />
                <stats-item v-if="stats.uniqueCards > 0">
                    <template #title>{{ $t("pages.collection.stats.uniqueCards.title") }}</template>
                    <template #value>{{ formatDecimals(stats.uniqueCards) }}</template>
                    <template #explanation>{{ $t("pages.collection.stats.uniqueCards.explanation") }}</template>
                </stats-item>
                <stats-item v-if="stats.mostOwnedCard">
                    <template #title>{{ $t("pages.collection.stats.mostOwnedCard.title") }}</template>
                    <template #value>{{ stats.mostOwnedCard.name }}</template>
                    <template #detail>
                        {{ formatDecimals(stats.mostOwnedCard.owned) }}
                        <span v-if="stats.mostOwnedCard.printingsOwned > 1" class="collection-page-stats__printings">
                            {{
                                $t("pages.collection.stats.mostOwnedCard.printings", {
                                    count: stats.mostOwnedCard.printingsOwned
                                })
                            }}
                        </span>
                    </template>
                    <template #explanation>{{ $t("pages.collection.stats.mostOwnedCard.explanation") }}</template>
                </stats-item>
                <stats-item v-if="stats.mostValuableCard">
                    <template #title>{{ $t("pages.collection.stats.mostValuableCard.title") }}</template>
                    <template #value>{{ stats.mostValuableCard.name }}</template>
                    <template #detail>
                        {{ formatPrice(stats.mostValuableCard.price) }}
                        <span v-if="stats.mostValuableCard.printingsOwned > 1" class="collection-page-stats__printings">
                            {{
                                $t("pages.collection.stats.mostValuableCard.printings", {
                                    count: stats.mostValuableCard.printingsOwned
                                })
                            }}
                        </span>
                    </template>
                    <template #explanation>{{ $t("pages.collection.stats.mostValuableCard.explanation") }}</template>
                </stats-item>
            </stats>
        </template>
    </accordion>
</template>

<style scoped lang="scss">
.collection-page-stats {
    margin-bottom: 1lh;

    &__title {
        display: flex;
        align-items: center;

        gap: 0.5rem;

        font-weight: 600;
    }

    &__printings {
        opacity: 0.7;
    }
}
</style>
