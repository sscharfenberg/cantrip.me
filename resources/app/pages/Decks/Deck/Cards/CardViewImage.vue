<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import DeckCardActionsMenu from "@/pages/Decks/Deck/Actions/DeckCardActionsMenu.vue";
import DeckGroupHeadline from "@/pages/Decks/Deck/Cards/DeckGroupHeadline.vue";
import FaceImageLazy from "@/pages/Decks/Deck/Cards/FaceImageLazy.vue";
import CompanionSection from "@/pages/Decks/Deck/Companion/CompanionSection.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import type { DeckCardGroup } from "Composables/useDeckGrouping.ts";
import { useDeckSections } from "Composables/useDeckSections.ts";
import type { DeckSort } from "Composables/useDeckSort.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion, DeckMeta } from "Types/deckPage";
const props = defineProps<{
    /** Full deck meta (for companion capabilities + format flags). */
    deck: DeckMeta;
    /** Commanders / command zone cards with full oracle + printing data. */
    commanders: DeckCommander[];
    /** Currently-set companion card, or null. */
    companion: DeckCompanion | null;
    /** All cards in the deck with full oracle + printing data. */
    cards: DeckCardRow[];
    /** User-defined categories for this deck. */
    categories: DeckCategoryRow[];
    /** Active sort mode — by mana value or alphabetically by name. */
    sortMode: DeckSort;
    /** Maximum length for a category name — forwarded to the actions menu. */
    categoryNameMax: number;
    /** Maximum copies allowed by the deck's format. */
    maxCopies: number;
    /** Whether the deck's format is singleton. */
    isSingleton: boolean;
}>();
const { t } = useI18n();
/** Image view has no drag — category moves happen via the actions menu. */
const draggedTypeGroup = ref<DeckCardGroup | null>(null);
const { allGroups } = useDeckSections(
    () => props.cards,
    () => props.commanders,
    () => props.companion,
    () => props.categories,
    () => props.sortMode,
    t,
    draggedTypeGroup
);
</script>

<template>
    <div class="image-card-groups">
        <section v-if="commanders.length" class="image-card-group">
            <deck-group-headline>{{ $t("pages.deck.commanders") }} ({{ commanders.length }})</deck-group-headline>
            <ul class="image-card-group__list">
                <face-image-lazy
                    v-for="commander in commanders"
                    :key="commander.oracle_card_id"
                    :card-image0="commander.default_card.card_image_0"
                    :card-image1="commander.default_card.card_image_1"
                    :name="commander.name"
                />
            </ul>
        </section>
        <section v-if="companion" class="image-card-group">
            <deck-group-headline>{{ $t("pages.deck.companion.heading") }}</deck-group-headline>
            <companion-section :deck-id="deck.id" :companion="companion" variant="image" />
        </section>
        <section v-for="group in allGroups" :key="group.key" class="image-card-group">
            <deck-group-headline>{{ group.label }} ({{ group.count }})</deck-group-headline>
            <ul class="image-card-group__list">
                <face-image-lazy
                    v-for="card in group.cards"
                    :key="card.id"
                    :card-image0="card.default_card.card_image_0"
                    :card-image1="card.default_card.card_image_1"
                    :name="card.name"
                >
                    <span class="card__qty">{{ card.quantity }}x</span>
                    <icon
                        v-if="card.is_illegal"
                        v-tooltip="$t('pages.deck.illegal')"
                        name="error"
                        :size="2"
                        :additional-classes="['card__illegal']"
                    />
                    <deck-card-actions-menu
                        :deck-id="props.deck.id"
                        :card="card"
                        :cards="props.cards"
                        :categories="props.categories"
                        :category-name-max="props.categoryNameMax"
                        :max-copies="props.maxCopies"
                        :is-singleton="props.isSingleton"
                        :is-medium-button="true"
                    />
                </face-image-lazy>
            </ul>
        </section>
    </div>
    <paragraph v-if="!commanders.length && !allGroups.length">{{ $t("pages.deck.no_cards") }}</paragraph>
</template>
