<script setup lang="ts">
import { ref } from "vue";
import { VueDraggable } from "vue-draggable-plus";
import { useI18n } from "vue-i18n";
import DeckCardActionsMenu from "@/pages/Decks/Deck/Actions/DeckCardActionsMenu.vue";
import DeckGroupHeadline from "@/pages/Decks/Deck/Cards/DeckGroupHeadline.vue";
import DeckAddGroupModal from "@/pages/Decks/Deck/Modals/DeckAddGroupModal.vue";
import DeckCardPreviewModal from "@/pages/Decks/Deck/Modals/DeckCardPreviewModal.vue";
import CardImagePreview from "Components/Card/CardImagePreview.vue";
import ManaCost from "Components/Card/ManaCost.vue";
import Icon from "Components/UI/Icon.vue";
import { useDeckCardDrag } from "Composables/useDeckCardDrag.ts";
import { useDeckSections } from "Composables/useDeckSections.ts";
import type { DeckSort } from "Composables/useDeckSort.ts";
import { useResponsiveColumns } from "Composables/useResponsiveColumns.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander } from "Types/deckPage";
/** Shape of the data needed by the preview modal. */
interface PreviewTarget {
    name: string;
    cardImage0: string | null;
    cardImage1: string | null;
}
const props = defineProps<{
    /** UUID of the deck. */
    deckId: string;
    /** Commanders / command zone cards with full oracle + printing data. */
    commanders: DeckCommander[];
    /** All cards in the deck with full oracle + printing data. */
    cards: DeckCardRow[];
    /** User-defined categories for this deck. */
    categories: DeckCategoryRow[];
    /** Active sort mode — by mana value or alphabetically by name. */
    sortMode: DeckSort;
    /** Maximum length for a category name. */
    categoryNameMax: number;
    /** Maximum copies allowed by the deck's format (e.g. 4, or 1 for singleton). */
    maxCopies: number;
    /** Whether the deck's format is singleton. */
    isSingleton: boolean;
}>();
const { t } = useI18n();
const {
    dragging,
    draggedTypeGroup,
    onDragStart,
    onDragEnd,
    isUnavailable,
    groupFor,
    dropTargetList,
    createGroupTarget,
    showCreateGroupModal,
    droppedCard,
    onDropToCreateGroup,
    onDropToGroup
} = useDeckCardDrag(props.deckId, () => props.cards);
const { sections, dragTargets } = useDeckSections(
    () => props.cards,
    () => props.commanders,
    () => props.categories,
    () => props.sortMode,
    t,
    draggedTypeGroup
);
const { containerRef, columns } = useResponsiveColumns(sections, {
    minColWidth: 256,
    maxColumns: 4,
    colGap: 16
});
/** The card currently shown in the preview modal, or null when hidden. */
const previewTarget = ref<PreviewTarget | null>(null);
</script>

<template>
    <div ref="containerRef" class="card-groups">
        <div v-for="(col, ci) in columns" :key="ci" class="card-groups__column">
            <template
                v-for="section in col"
                :key="section.kind === 'commanders' ? 'cmd' : section.kind === 'group' ? section.group.key : 'new'"
            >
                <section
                    v-if="section.kind === 'commanders'"
                    class="card-group"
                    :class="{ 'card-group--unavailable': dragging }"
                >
                    <deck-group-headline
                        >{{ $t("pages.deck.commanders") }} ({{ section.commanders.length }})</deck-group-headline
                    >
                    <ul class="card-group__list">
                        <li v-for="commander in section.commanders" :key="commander.oracle_card_id" class="card">
                            <card-image-preview
                                :src="commander.default_card.card_image_0"
                                :alt="commander.name"
                                @preview="
                                    previewTarget = {
                                        name: commander.name,
                                        cardImage0: commander.default_card.card_image_0,
                                        cardImage1: commander.default_card.card_image_1
                                    }
                                "
                            >
                                <span class="card__qty">1x </span>{{ commander.name }}
                            </card-image-preview>
                            <mana-cost v-for="(cost, i) in commander.mana_cost" :key="i" :mana-cost="cost" />
                        </li>
                    </ul>
                </section>
                <section
                    v-else-if="section.kind === 'group'"
                    class="card-group"
                    :class="{
                        'card-group--unavailable': isUnavailable(section.group)
                    }"
                >
                    <deck-group-headline>{{ section.group.label }} ({{ section.group.count }})</deck-group-headline>
                    <VueDraggable
                        :model-value="section.group.cards"
                        tag="ul"
                        class="card-group__list"
                        :class="{ 'card-group__list--droppable': dragging && !isUnavailable(section.group) }"
                        handle=".card__drag-handle"
                        :group="groupFor(section.group)"
                        :sort="false"
                        ghost-class="card--ghost"
                        @start="onDragStart"
                        @end="onDragEnd"
                        @add="(evt: { item: HTMLElement }) => onDropToGroup(evt, section.group.categoryId)"
                    >
                        <li v-for="card in section.group.cards" :key="card.id" :data-card-id="card.id" class="card">
                            <span class="card__drag-handle"><icon name="drag" :size="1" /></span>
                            <card-image-preview
                                :src="card.default_card.card_image_0"
                                :alt="card.name"
                                @preview="
                                    previewTarget = {
                                        name: card.name,
                                        cardImage0: card.default_card.card_image_0,
                                        cardImage1: card.default_card.card_image_1
                                    }
                                "
                            >
                                <span class="card__qty">{{ card.quantity }}x </span>{{ card.name }}
                            </card-image-preview>
                            <mana-cost v-for="(cost, i) in card.mana_cost" :key="i" :mana-cost="cost" />
                            <deck-card-actions-menu
                                :deck-id="props.deckId"
                                :card-id="card.id"
                                :name="card.name"
                                :quantity="card.quantity"
                                :is-basic-land="card.is_basic_land"
                                :max-copies="props.maxCopies"
                                :is-singleton="props.isSingleton"
                            />
                        </li>
                    </VueDraggable>
                </section>
                <section
                    v-else-if="dragging && section.kind === 'create-group'"
                    class="card-group card-group__drop-target"
                >
                    <icon name="add" :size="2" />
                    {{ $t("pages.deck.create_group.link") }}
                    <VueDraggable
                        v-model="dropTargetList"
                        tag="div"
                        class="card-group__drop-zone"
                        :group="createGroupTarget"
                        ghost-class="card--ghost"
                        @add="onDropToCreateGroup"
                    />
                </section>
            </template>
            <!-- Extra drop targets rendered outside the column distribution
                 so that appearing mid-drag doesn't cause a redistribution. -->
            <template v-if="dragging && ci === columns.length - 1">
                <section v-for="target in dragTargets" :key="target.key" class="card-group">
                    <deck-group-headline>{{ target.label }} ({{ target.count }})</deck-group-headline>
                    <VueDraggable
                        :model-value="target.cards"
                        tag="ul"
                        class="card-group__list card-group__list--droppable"
                        handle=".card__drag-handle"
                        :group="groupFor(target)"
                        :sort="false"
                        ghost-class="card--ghost"
                        @add="(evt: { item: HTMLElement }) => onDropToGroup(evt, target.categoryId)"
                    />
                </section>
            </template>
        </div>
    </div>
    <p v-if="!columns.length">{{ $t("pages.deck.no_cards") }}</p>
    <deck-add-group-modal
        v-if="showCreateGroupModal && droppedCard"
        :deck-id="props.deckId"
        :card="droppedCard"
        :category-name-max="props.categoryNameMax"
        @close="
            showCreateGroupModal = false;
            droppedCard = null;
        "
    />
    <deck-card-preview-modal
        v-if="previewTarget"
        :name="previewTarget.name"
        :card-image0="previewTarget.cardImage0"
        :card-image1="previewTarget.cardImage1"
        @close="previewTarget = null"
    />
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

.card {
    display: flex;
    align-items: center;

    padding: map.get(s.$pages, "deck", "card", "padding");
    border: map.get(s.$pages, "deck", "card", "border") solid map.get(c.$pages, "deck", "card", "border");
    gap: map.get(s.$pages, "deck", "card", "gap");

    background-color: map.get(c.$pages, "deck", "card", "background");
    color: map.get(c.$pages, "deck", "card", "surface");
    border-radius: map.get(s.$pages, "deck", "card", "radius");

    &__drag-handle {
        cursor: move;
    }

    &__qty {
        font-weight: 600;
    }
}

:deep(.card-preview__trigger) {
    flex-grow: 1;

    overflow: hidden;

    padding: 0;

    white-space: nowrap;
    text-overflow: ellipsis;
}
</style>
