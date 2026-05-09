<script setup lang="ts">
import { ref } from "vue";
import { VueDraggable } from "vue-draggable-plus";
import { useI18n } from "vue-i18n";
import CollectionImplicitBadge from "@/components/Deck/CollectionImplicitBadge.vue";
import CollectionStatusBadge from "@/components/Deck/CollectionStatusBadge.vue";
import DeckCardActionsMenu from "@/pages/Deck/Actions/DeckCardActionsMenu.vue";
import DeckCommanderActionsMenu from "@/pages/Deck/Actions/DeckCommanderActionsMenu.vue";
import DeckAddGroupModal from "@/pages/Deck/Modals/DeckAddGroupModal.vue";
import DeckCompanionSection from "@/pages/Deck/Sections/DeckCompanionSection.vue";
import DeckGroupHeadline from "@/pages/Deck/Sections/DeckGroupHeadline.vue";
import CardImagePreview from "Components/Card/CardImagePreview.vue";
import CardPreviewModal from "Components/Card/CardPreviewModal.vue";
import ManaCost from "Components/Card/ManaCost.vue";
import Icon from "Components/UI/Icon.vue";
import { useDeckCardDrag } from "Composables/useDeckCardDrag.ts";
import { useDeckHighlight } from "Composables/useDeckHighlight.ts";
import { useDeckSections } from "Composables/useDeckSections.ts";
import type { DeckSort } from "Composables/useDeckSort.ts";
import { useRecentlyAddedId } from "Composables/useRecentlyAdded.ts";
import { useResponsiveColumns } from "Composables/useResponsiveColumns.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion, DeckMeta } from "Types/deckPage.ts";
const props = defineProps<{
    /** Full deck meta (for companion capabilities + format flags). */
    deck: DeckMeta;
    /**
     * True when the request user owns the deck. Hides per-card / commander /
     * companion action menus, drag handles and drag-drop drop targets so
     * non-owners get a read-only view.
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
    /** Maximum length for a category name. */
    categoryNameMax: number;
    /** Maximum copies allowed by the deck's format (e.g. 4, or 1 for singleton). */
    maxCopies: number;
    /** Whether the deck's format is singleton. */
    isSingleton: boolean;
    /** Effective collection-integration mode — only mode C renders per-card status badges. */
    collectionMode: "A" | "B" | "C";
}>();
const { t } = useI18n();
const { isHighlighted } = useDeckHighlight();
/** Oracle id of a card just added via quick-add — used to flash its row briefly. */
const recentlyAddedId = useRecentlyAddedId();
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
} = useDeckCardDrag(props.deck.id, () => props.cards);
const { sections, dragTargets } = useDeckSections(
    () => props.cards,
    () => props.commanders,
    () => (props.deck.allows_companion ? props.companion : null),
    () => props.categories,
    () => props.sortMode,
    () => props.deck.max_sideboard_size > 0,
    t,
    draggedTypeGroup
);
const { containerRef, columns } = useResponsiveColumns(sections, {
    minColWidth: 320,
    maxColumns: 3,
    colGap: 16,
    // Weight ≈ visual row count: 1 for the headline + one row per unique
    // card. Without this, "Creatures (40)" and "Enchantments (3)" each
    // count as one section and get one column apiece — yielding a 40-row
    // column next to a 3-row column. With it, the partition balances the
    // total row count per column.
    weight: section => {
        if (section.kind === "commanders") return 1 + section.commanders.length;
        if (section.kind === "companion") return 2;
        return 1 + section.group.cards.length;
    }
});
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
</script>

<template>
    <div ref="containerRef" class="text-card-groups">
        <div v-for="(col, ci) in columns" :key="ci" class="text-card-groups__column">
            <template
                v-for="section in col"
                :key="section.kind === 'commanders' ? 'cmd' : section.kind === 'companion' ? 'cmp' : section.group.key"
            >
                <section
                    v-if="section.kind === 'commanders'"
                    class="text-card-group"
                    :class="{ 'text-card-group--unavailable': dragging }"
                >
                    <deck-group-headline
                        >{{ $t("pages.deck.commanders") }} ({{ section.commanders.length }})</deck-group-headline
                    >
                    <ul class="text-card-group__list">
                        <li
                            v-for="commander in section.commanders"
                            :key="commander.oracle_card_id"
                            class="card"
                            :class="{ highlighted: isHighlighted(commander) }"
                        >
                            <card-image-preview
                                :src="commander.default_card.card_image_0"
                                :alt="commander.name"
                                @preview="openPreview(commander.default_card.id)"
                            >
                                <span class="card__qty">1x </span>{{ commander.name }}
                            </card-image-preview>
                            <icon
                                v-if="commander.is_illegal"
                                v-tooltip="$t('pages.deck.illegal')"
                                name="error"
                                :size="1"
                                :additional-classes="['card__illegal']"
                            />
                            <collection-status-badge
                                v-if="collectionMode === 'C' && commander.collection_status"
                                :status="commander.collection_status"
                                variant="inline"
                            />
                            <collection-implicit-badge
                                v-if="collectionMode === 'B' && commander.collection_implicit_status"
                                :status="commander.collection_implicit_status"
                                :quantity="1"
                                variant="inline"
                            />
                            <mana-cost :mana-cost="commander.mana_cost" />
                            <deck-commander-actions-menu
                                v-if="isOwner"
                                :deck-id="deck.id"
                                :deck-card-id="commander.deck_card_id"
                                :oracle-card-id="commander.oracle_card_id"
                                :commander-name="commander.name"
                                :format="deck.format"
                                :default-card-id="commander.default_card.id"
                                :hero-card-id="deck.hero_card?.id ?? null"
                                :collection-mode="collectionMode"
                            />
                        </li>
                    </ul>
                </section>
                <section
                    v-else-if="section.kind === 'companion'"
                    class="text-card-group"
                    :class="{ 'text-card-group--unavailable': dragging }"
                >
                    <deck-group-headline>{{ $t("pages.deck.companion.heading") }}</deck-group-headline>
                    <deck-companion-section
                        :deck-id="deck.id"
                        :companion="section.companion"
                        variant="text"
                        :is-owner="isOwner"
                        :hero-card-id="deck.hero_card?.id ?? null"
                        :collection-mode="collectionMode"
                        @preview="id => openPreview(id)"
                    />
                </section>
                <section
                    v-else-if="section.kind === 'group'"
                    class="text-card-group"
                    :class="{
                        'text-card-group--unavailable': isUnavailable(section.group)
                    }"
                >
                    <deck-group-headline>{{ section.group.label }} ({{ section.group.count }})</deck-group-headline>
                    <VueDraggable
                        :model-value="section.group.cards"
                        tag="ul"
                        class="text-card-group__list"
                        :class="{ 'text-card-group__list--droppable': dragging && !isUnavailable(section.group) }"
                        handle=".card__drag-handle"
                        :group="groupFor(section.group)"
                        :sort="false"
                        ghost-class="card--ghost"
                        @start="onDragStart"
                        @end="onDragEnd"
                        @add="
                            (evt: { item: HTMLElement }) =>
                                onDropToGroup(evt, section.group.categoryId, section.group.zone)
                        "
                    >
                        <li
                            v-for="card in section.group.cards"
                            :key="card.id"
                            :data-card-id="card.id"
                            class="card"
                            :class="{
                                'card--just-added': recentlyAddedId === card.oracle_card_id,
                                highlighted: isHighlighted(card)
                            }"
                        >
                            <span v-if="isOwner" class="card__drag-handle"><icon name="drag" :size="1" /></span>
                            <card-image-preview
                                :src="card.default_card.card_image_0"
                                :alt="card.name"
                                @preview="openPreview(card.default_card.id, card.quantity)"
                            >
                                <span class="card__qty">{{ card.quantity }}x </span>{{ card.name }}
                            </card-image-preview>
                            <icon
                                v-if="card.is_illegal"
                                v-tooltip="$t('pages.deck.illegal')"
                                name="error"
                                :size="1"
                                :additional-classes="['card__illegal']"
                            />
                            <icon
                                v-if="card.is_game_changer && deck.uses_game_changer_list"
                                v-tooltip="$t('pages.deck.game_changer')"
                                name="balance"
                                :additional-classes="['card__game-changer']"
                            />
                            <icon
                                v-if="card.is_mld && deck.uses_game_changer_list"
                                v-tooltip="$t('pages.deck.mld')"
                                name="landslide"
                                :additional-classes="['card__mld']"
                            />
                            <collection-status-badge
                                v-if="collectionMode === 'C' && card.collection_status"
                                :status="card.collection_status"
                                variant="inline"
                            />
                            <collection-implicit-badge
                                v-if="collectionMode === 'B' && card.collection_implicit_status"
                                :status="card.collection_implicit_status"
                                :quantity="card.quantity"
                                variant="inline"
                            />
                            <mana-cost :mana-cost="card.mana_cost" />
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
                            />
                        </li>
                    </VueDraggable>
                </section>
            </template>
            <!-- Extra drop targets rendered outside the column distribution
                 so that appearing mid-drag doesn't cause a redistribution.
                 Implicitly gated on `isOwner` because non-owners can't start
                 a drag (no handle), but kept explicit for defense-in-depth. -->
            <template v-if="isOwner && dragging && ci === columns.length - 1">
                <section v-for="target in dragTargets" :key="target.key" class="text-card-group">
                    <deck-group-headline>{{ target.label }} ({{ target.count }})</deck-group-headline>
                    <VueDraggable
                        :model-value="target.cards"
                        tag="ul"
                        class="text-card-group__list text-card-group__list--droppable"
                        handle=".card__drag-handle"
                        :group="groupFor(target)"
                        :sort="false"
                        ghost-class="card--ghost"
                        @add="(evt: { item: HTMLElement }) => onDropToGroup(evt, target.categoryId, target.zone)"
                    />
                </section>
                <section class="text-card-group text-card-group__drop-target">
                    <icon name="add" :size="2" />
                    {{ $t("pages.deck.create_group.link") }}
                    <VueDraggable
                        v-model="dropTargetList"
                        tag="div"
                        class="text-card-group__drop-zone"
                        :group="createGroupTarget"
                        ghost-class="card--ghost"
                        @add="onDropToCreateGroup"
                    />
                </section>
            </template>
        </div>
    </div>
    <p v-if="!columns.length">{{ $t("pages.deck.no_cards") }}</p>
    <deck-add-group-modal
        v-if="showCreateGroupModal && droppedCard"
        :deck-id="props.deck.id"
        :card="droppedCard"
        :category-name-max="props.categoryNameMax"
        @close="
            showCreateGroupModal = false;
            droppedCard = null;
        "
    />
    <card-preview-modal v-if="previewUrl" :preview-url="previewUrl" @close="previewUrl = null" />
</template>

<style lang="scss" scoped>
// Override CardImagePreview's own scoped padding default. Lives here (not in
// _text-group.scss) because that partial is inside @layer components, which
// always loses to CardImagePreview's own unlayered scoped style regardless of
// specificity. :deep() reaches DeckCompanionSection too, which is rendered inside
// this component.
:deep(.card-preview__trigger) {
    flex-grow: 1;

    overflow: hidden;

    padding: 0;

    white-space: nowrap;
    text-overflow: ellipsis;
}
</style>
