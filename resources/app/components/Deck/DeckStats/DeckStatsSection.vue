<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DeckStatsCategories from "Components/Deck/DeckStats/DeckStatsCategories.vue";
import DeckStatsColors from "Components/Deck/DeckStats/DeckStatsColors.vue";
import DeckStatsManacurve from "Components/Deck/DeckStats/DeckStatsManacurve.vue";
import Accordion from "Components/UI/Accordion.vue";
import Icon from "Components/UI/Icon.vue";
import { useDeckHighlight } from "Composables/useDeckHighlight.ts";
import { useDeckStats, type ColorPipTally } from "Composables/useDeckStats.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion } from "Types/deckPage.ts";
const props = defineProps<{
    cards: DeckCardRow[];
    commanders: DeckCommander[];
    companion: DeckCompanion | null;
    categories: DeckCategoryRow[];
    /** Deck format slug — drives Karsten's per-format mana-source tables. */
    format: string;
}>();
const { t } = useI18n();
const {
    manaCurve,
    averageManaValue,
    costPips,
    productionPips,
    karstenAnalysis,
    karstenCombined,
    typeCounts,
    subtypeBreakdowns,
    categoryCounts,
} = useDeckStats(
    () => props.cards,
    () => props.commanders,
    () => props.companion,
    () => props.categories,
    () => props.format
);
const { hasHighlight, clear } = useDeckHighlight();
/**
 * Strict gate for the color donut: hide when either ring would be
 * empty. A lone ring (e.g. all-colorless costs) is misleading because
 * the SVG center looks the same as a fully-populated chart, and the
 * chart's job is to compare cost against production at a glance.
 */
const tallyTotal = (t: ColorPipTally): number => t.W + t.U + t.B + t.R + t.G;
const showColors = computed(() => tallyTotal(costPips.value) > 0 && tallyTotal(productionPips.value) > 0);
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
                <deck-stats-colors
                    v-if="showColors"
                    :cost="costPips"
                    :production="productionPips"
                    :karsten="karstenAnalysis"
                    :karsten-combined="karstenCombined"
                    :format="format"
                />
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
