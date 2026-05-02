<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import CollectionImplicitBadge from "@/components/Deck/CollectionImplicitBadge.vue";
import CollectionStatusBadge from "@/components/Deck/CollectionStatusBadge.vue";
import DeckCardActionsMenu from "@/pages/Deck/Actions/DeckCardActionsMenu.vue";
import DeckCommanderActionsMenu from "@/pages/Deck/Actions/DeckCommanderActionsMenu.vue";
import FaceImageLazy from "@/pages/Deck/Cards/FaceImageLazy.vue";
import DeckCompanionSection from "@/pages/Deck/Sections/DeckCompanionSection.vue";
import DeckGroupHeadline from "@/pages/Deck/Sections/DeckGroupHeadline.vue";
import type { DeckCardGroup } from "@/utils/deckGrouping.ts";
import CardPreviewModal from "Components/Card/CardPreviewModal.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import { useDeckSections } from "Composables/useDeckSections.ts";
import type { DeckSort } from "Composables/useDeckSort.ts";
import { useRecentlyAddedId } from "Composables/useRecentlyAdded.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion, DeckMeta } from "Types/deckPage.ts";
const props = defineProps<{
    /** Full deck meta (for companion capabilities + format flags). */
    deck: DeckMeta;
    /**
     * True when the request user owns the deck. Hides per-card / commander /
     * companion action menus so non-owners get a read-only view.
     */
    isOwner: boolean;
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
    /** Effective collection-integration mode — only mode C renders per-card status badges. */
    collectionMode: "A" | "B" | "C";
}>();
const { t } = useI18n();
/** Image view has no drag — category moves happen via the actions menu. */
const draggedTypeGroup = ref<DeckCardGroup | null>(null);
/** Oracle id of a card just added via quick-add — used to flash its row briefly. */
const recentlyAddedId = useRecentlyAddedId();
/**
 * Preview-modal endpoint URL, or null when hidden. `quantity` is sent
 * for deck cards (so the modal can show the deck's copy count + implied
 * total) and omitted for commanders / companion (always 1 — modal stays
 * clean).
 */
const previewUrl = ref<string | null>(null);
const openPreview = (id: string | null, quantity?: number): void => {
    if (!id) return;
    previewUrl.value = quantity ? `/cards/${id}/preview?quantity=${quantity}` : `/cards/${id}/preview`;
};
const { allGroups } = useDeckSections(
    () => props.cards,
    () => props.commanders,
    () => props.companion,
    () => props.categories,
    () => props.sortMode,
    () => props.deck.max_sideboard_size > 0,
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
                    @preview="openPreview(commander.default_card.id)"
                >
                    <deck-commander-actions-menu
                        v-if="isOwner"
                        :deck-id="deck.id"
                        :oracle-card-id="commander.oracle_card_id"
                        :commander-name="commander.name"
                        :format="deck.format"
                        :default-card-id="commander.default_card.id"
                        :hero-card-id="deck.hero_card?.id ?? null"
                        :is-medium-button="true"
                    />
                </face-image-lazy>
            </ul>
        </section>
        <section v-if="deck.allows_companion && companion" class="image-card-group">
            <deck-group-headline>{{ $t("pages.deck.companion.heading") }}</deck-group-headline>
            <deck-companion-section
                :deck-id="deck.id"
                :companion="companion"
                variant="image"
                :is-owner="isOwner"
                :hero-card-id="deck.hero_card?.id ?? null"
                @preview="id => openPreview(id)"
            />
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
                    :class="{ 'card--just-added': recentlyAddedId === card.oracle_card_id }"
                    @preview="openPreview(card.default_card.id, card.quantity)"
                >
                    <icon
                        v-if="card.is_game_changer && deck.uses_game_changer_list"
                        v-tooltip="$t('pages.deck.game_changer')"
                        name="balance"
                        :size="2"
                        :additional-classes="['card__game-changer']"
                    />
                    <span class="card__qty">{{ card.quantity }}x</span>
                    <icon
                        v-if="card.is_illegal"
                        v-tooltip="$t('pages.deck.illegal')"
                        name="error"
                        :size="2"
                        :additional-classes="['card__illegal']"
                    />
                    <collection-status-badge
                        v-if="collectionMode === 'C' && card.collection_status"
                        :status="card.collection_status"
                        variant="corner"
                    />
                    <collection-implicit-badge
                        v-if="collectionMode === 'B' && card.collection_implicit_status"
                        :status="card.collection_implicit_status"
                        :quantity="card.quantity"
                        variant="corner"
                    />
                    <deck-card-actions-menu
                        v-if="isOwner"
                        :deck-id="props.deck.id"
                        :card="card"
                        :cards="props.cards"
                        :categories="props.categories"
                        :category-name-max="props.categoryNameMax"
                        :max-copies="props.maxCopies"
                        :is-singleton="props.isSingleton"
                        :has-sideboard="props.deck.max_sideboard_size > 0"
                        :hero-card-id="props.deck.hero_card?.id ?? null"
                        :collection-mode="collectionMode"
                        :is-medium-button="true"
                    />
                </face-image-lazy>
            </ul>
        </section>
    </div>
    <paragraph v-if="!commanders.length && !allGroups.length">{{ $t("pages.deck.no_cards") }}</paragraph>
    <card-preview-modal v-if="previewUrl" :preview-url="previewUrl" @close="previewUrl = null" />
</template>
