<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DeckStatsCategories from "Components/Deck/DeckStats/DeckStatsCategories.vue";
import DeckStatsColors from "Components/Deck/DeckStats/DeckStatsColors.vue";
import DeckStatsManacurve from "Components/Deck/DeckStats/DeckStatsManacurve.vue";
import Accordion from "Components/UI/Accordion.vue";
import Icon from "Components/UI/Icon.vue";
import { useDeckStats } from "Composables/useDeckStats.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion, DeckStatsSelection } from "Types/deckPage.ts";
// `selectManaValue` and `selectCategory` are forwarded from the two
// inner panels when the user clicks a column / bar. The deck page
// mirrors them onto the card views so the active selection can drive
// highlighting; mutual exclusion (only one non-null at a time) is
// enforced one level up.
const emit = defineEmits<{
    (e: "selectManaValue", cmc: number | null): void;
    (e: "selectCategory", selection: DeckStatsSelection | null): void;
    (e: "clear"): void;
}>();
const props = defineProps<{
    cards: DeckCardRow[];
    commanders: DeckCommander[];
    companion: DeckCompanion | null;
    categories: DeckCategoryRow[];
    /** Controlled selections — owned by DeckPage, threaded down to the inner panels. */
    selectedManaValue: number | null;
    selectedCategory: DeckStatsSelection | null;
}>();
const { t } = useI18n();
const { manaCurve, averageManaValue, costPips, productionPips, typeCounts, subtypeBreakdowns, categoryCounts } =
    useDeckStats(
        () => props.cards,
        () => props.commanders,
        () => props.companion,
        () => props.categories
    );
/** True when any axis is currently selected — drives the "clear all" button visibility. */
const hasSelection = computed(() => props.selectedManaValue !== null || props.selectedCategory !== null);
</script>

<template>
    <accordion>
        <template #head
            ><span class="stats__hdl"><icon name="chart" />{{ t("pages.deck.stats.title") }}</span></template
        >
        <template #body>
            <div class="stats__body">
                <button v-if="hasSelection" type="button" class="btn-default" @click="emit('clear')">
                    <icon name="close" />
                    {{ t("pages.deck.clear_selection") }}
                </button>
                <deck-stats-manacurve
                    :curve="manaCurve"
                    :average="averageManaValue"
                    :selected-cmc="props.selectedManaValue"
                    @select="emit('selectManaValue', $event)"
                />
                <deck-stats-colors :cost="costPips" :production="productionPips" />
                <deck-stats-categories
                    :types="typeCounts"
                    :categories="categoryCounts"
                    :subtype-breakdowns="subtypeBreakdowns"
                    :selected-category="props.selectedCategory"
                    @select="emit('selectCategory', $event)"
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
