<script setup lang="ts">
import { useI18n } from "vue-i18n";
import DeckStatsCategories from "Components/Deck/DeckStats/DeckStatsCategories.vue";
import DeckStatsColors from "Components/Deck/DeckStats/DeckStatsColors.vue";
import DeckStatsManacurve from "Components/Deck/DeckStats/DeckStatsManacurve.vue";
import Accordion from "Components/UI/Accordion.vue";
import Icon from "Components/UI/Icon.vue";
import { useDeckHighlight } from "Composables/useDeckHighlight.ts";
import { useDeckStats } from "Composables/useDeckStats.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion } from "Types/deckPage.ts";
const props = defineProps<{
    cards: DeckCardRow[];
    commanders: DeckCommander[];
    companion: DeckCompanion | null;
    categories: DeckCategoryRow[];
}>();
const { t } = useI18n();
const { manaCurve, averageManaValue, costPips, productionPips, typeCounts, subtypeBreakdowns, categoryCounts } =
    useDeckStats(
        () => props.cards,
        () => props.commanders,
        () => props.companion,
        () => props.categories
    );
const { hasHighlight, clear } = useDeckHighlight();
</script>

<template>
    <accordion>
        <template #head
            ><span class="stats__hdl"><icon name="chart" />{{ t("pages.deck.stats.title") }}</span></template
        >
        <template #body>
            <div class="stats__body">
                <button v-if="hasHighlight" type="button" class="btn-default" @click="clear">
                    <icon name="close" />
                    {{ t("pages.deck.clear_selection") }}
                </button>
                <deck-stats-manacurve :curve="manaCurve" :average="averageManaValue" />
                <deck-stats-colors :cost="costPips" :production="productionPips" />
                <deck-stats-categories
                    :types="typeCounts"
                    :categories="categoryCounts"
                    :subtype-breakdowns="subtypeBreakdowns"
                />
            </div>
        </template>
    </accordion>
</template>

<style lang="scss" scoped>
.stats {
    &__hdl {
        display: flex;
        align-items: center;

        gap: 0.5rem;
    }

    &__body {
        display: flex;
        flex-direction: column;

        gap: 1lh;

        .btn-default {
            align-self: flex-start;
        }
    }
}
</style>
