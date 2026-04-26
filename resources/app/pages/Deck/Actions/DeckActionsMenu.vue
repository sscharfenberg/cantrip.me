<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, ref, useId } from "vue";
import { useI18n } from "vue-i18n";
import { hasDeletableContent } from "@/utils/deleteDeck.ts";
import type { DeleteDeckTarget } from "@/utils/deleteDeck.ts";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import type { DeckCardRow, DeckCategoryRow, DeckCompanion, DeckMeta } from "Types/deckPage.ts";
import DeckAddGroupModal from "../Modals/DeckAddGroupModal.vue";
import DeckCustomGroupsModal from "../Modals/DeckCustomGroupsModal.vue";
import DeleteDeckModal from "../Modals/DeleteDeckModal.vue";
const props = defineProps<{
    /** Deck metadata (name, format, state, colors, etc.). */
    deck: DeckMeta;
    /** Currently-set companion card, or null — drives the delete-confirm summary. */
    companion: DeckCompanion | null;
    /** All cards in the deck. */
    cards: DeckCardRow[];
    /** User-defined categories for this deck. */
    categories: DeckCategoryRow[];
    /** Maximum length for a category name. */
    categoryNameMax: number;
}>();
const { t } = useI18n();
const popoverId = useId();
const showCustomGroupsModal = ref(false);
const showCreateGroupModal = ref(false);
const showDeleteModal = ref(false);
/**
 * Adapter from this page's deck-detail props to the shared modal target.
 * `card_count` on `DeckMeta` is the controller-computed combined count
 * (deck cards + commanders), and `hero_card !== null` mirrors the
 * `has_image` flag the deck-list payload exposes.
 */
const deleteTarget = computed<DeleteDeckTarget>(() => ({
    id: props.deck.id,
    name: props.deck.name,
    cardCount: props.deck.card_count,
    hasCompanion: props.companion !== null,
    hasDescription: props.deck.description !== null && props.deck.description !== "",
    hasImage: props.deck.hero_card !== null
}));
/** Close the action popover programmatically. */
function closePopover(): void {
    const dialog = document.getElementById(popoverId);
    if (dialog !== null) dialog.hidePopover();
}
/** Open the custom groups modal and close the popover. */
function openCustomGroups(): void {
    closePopover();
    showCustomGroupsModal.value = true;
}
/** Open the create group modal and close the popover. */
function openCreateGroup(): void {
    closePopover();
    showCreateGroupModal.value = true;
}
/** Navigate to the deck-settings edit page. */
function onEditSettings(): void {
    closePopover();
    router.visit(`/decks/${props.deck.id}/edit`);
}
/**
 * Delete button handler. Skips the confirm prompt for an effectively-empty
 * deck and fires the DELETE directly. Same UX as the deck-list link.
 */
function onDeleteClick(): void {
    closePopover();
    if (hasDeletableContent(deleteTarget.value)) {
        showDeleteModal.value = true;
        return;
    }
    router.delete(`/decks/${props.deck.id}`);
}
</script>

<template>
    <pop-over
        icon="more"
        :aria-label="t('pages.decks.actions.label')"
        class-string="popover-button--rounded"
        :reference="popoverId"
        width="14rem"
    >
        <ul class="popover-list">
            <li>
                <button class="popover-list-item" @click="onEditSettings">
                    <icon name="edit" :size="1" />
                    {{ $t("pages.create_deck.edit_link") }}
                </button>
            </li>
            <li>
                <button class="popover-list-item" @click="openCreateGroup">
                    <icon name="add" :size="1" />
                    {{ $t("pages.deck.create_group.link") }}
                </button>
            </li>
            <li>
                <button class="popover-list-item" @click="openCustomGroups">
                    <icon name="edit" :size="1" />
                    {{ $t("pages.deck.custom_groups.link") }}
                </button>
            </li>
            <li>
                <button class="popover-list-item popover-list-item--error" @click="onDeleteClick">
                    <icon name="delete" :size="1" />
                    {{ $t("pages.decks.actions.delete") }}
                </button>
            </li>
        </ul>
    </pop-over>
    <deck-custom-groups-modal
        v-if="showCustomGroupsModal"
        :deck-id="props.deck.id"
        :cards="props.cards"
        :categories="props.categories"
        :category-name-max="props.categoryNameMax"
        @close="showCustomGroupsModal = false"
    />
    <deck-add-group-modal
        v-if="showCreateGroupModal"
        :deck-id="props.deck.id"
        :category-name-max="props.categoryNameMax"
        @close="showCreateGroupModal = false"
    />
    <delete-deck-modal v-if="showDeleteModal" :target="deleteTarget" @close="showDeleteModal = false" />
</template>
