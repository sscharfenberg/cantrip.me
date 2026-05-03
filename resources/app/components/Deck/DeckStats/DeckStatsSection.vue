<script setup lang="ts">
import { useI18n } from "vue-i18n";
import DeckStatsCategories from "Components/Deck/DeckStats/DeckStatsCategories.vue";
import DeckStatsColors from "Components/Deck/DeckStats/DeckStatsColors.vue";
import DeckStatsManacurve from "Components/Deck/DeckStats/DeckStatsManacurve.vue";
import Accordion from "Components/UI/Accordion.vue";
import Icon from "Components/UI/Icon.vue";
import { useDeckStats } from "Composables/useDeckStats.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion } from "Types/deckPage.ts";
// `selectManaValue` is forwarded from DeckStatsManacurve when the user
// clicks a mana-curve column. The deck page mirrors it onto the card
// views so the active selection can drive filtering / highlighting.
const emit = defineEmits<{
    (e: "selectManaValue", cmc: number | null): void;
}>();
const props = defineProps<{
    cards: DeckCardRow[];
    commanders: DeckCommander[];
    companion: DeckCompanion | null;
    categories: DeckCategoryRow[];
}>();
const { t } = useI18n();
const { manaCurve, averageManaValue, costPips, productionPips, typeCounts, categoryCounts } = useDeckStats(
    () => props.cards,
    () => props.commanders,
    () => props.companion,
    () => props.categories
);
</script>

<template>
    <accordion>
        <template #head
            ><span class="stats"><icon name="chart" />{{ t("pages.deck.stats.title") }}</span></template
        >
        <template #body>
            <deck-stats-manacurve
                :curve="manaCurve"
                :average="averageManaValue"
                @select="emit('selectManaValue', $event)"
            />
            <deck-stats-colors :cost="costPips" :production="productionPips" />
            <deck-stats-categories :types="typeCounts" :categories="categoryCounts" />
        </template>
    </accordion>
</template>

<style lang="scss" scoped>
.stats {
    display: flex;
    align-items: center;

    gap: 0.5rem;
}
</style>
