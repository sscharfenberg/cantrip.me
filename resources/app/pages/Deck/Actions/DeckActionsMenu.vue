<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { computed, ref, useId } from "vue";
import { useI18n } from "vue-i18n";
import { hasDeletableContent } from "@/utils/deleteDeck.ts";
import type { DeleteDeckTarget } from "@/utils/deleteDeck.ts";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import type { DeckCardRow, DeckCategoryRow } from "Types/deckPage.ts";
import AddAllToCollectionModal from "../Modals/AddAllToCollectionModal.vue";
import DeckAddGroupModal from "../Modals/DeckAddGroupModal.vue";
import DeckCustomGroupsModal from "../Modals/DeckCustomGroupsModal.vue";
import DeleteDeckModal from "../Modals/DeleteDeckModal.vue";

/** Container option shipped to the AddAllToCollectionModal dropdown. */
export interface DeckActionsContainer {
    id: string;
    name: string;
    type: string;
    is_deckbox: boolean;
}

/**
 * Lean deck shape this menu needs. Both the deck-show page (DeckMeta +
 * companion) and the deck-list row (DeckRow) adapt to this — see callers.
 */
export interface DeckActionsTarget {
    id: string;
    name: string;
    state: string;
    visibility: string;
    card_count: number;
    has_companion: boolean;
    has_description: boolean;
    has_image: boolean;
}

const props = withDefaults(
    defineProps<{
        /** Deck identity + the flags the delete-confirm summary needs. */
        deck: DeckActionsTarget;
        /**
         * True when the request user owns the deck. Owners see every
         * action; non-owners (public-deck visitors) get a menu reduced
         * to just "Download CSV". Defaults to true so the deck-list
         * popover (own-decks page) doesn't have to pass it explicitly.
         */
        isOwner?: boolean;
        /**
         * Effective collection-integration mode. When provided, drives "Set to
         * finished": mode A patches the state directly, modes B and C open
         * the wizard. When omitted, the state-flip items are hidden.
         */
        collectionMode?: "A" | "B" | "C";
        /**
         * All cards in the deck. When omitted (together with `categories` /
         * `categoryNameMax`), the create-group + custom-groups items are
         * hidden — useful for callers that don't have card-level data on hand
         * (e.g. the deck-list row popover).
         */
        cards?: DeckCardRow[];
        /** User-defined categories for this deck. */
        categories?: DeckCategoryRow[];
        /** Maximum length for a category name. */
        categoryNameMax?: number;
        /**
         * Owner's containers — when provided, drives the "Add all cards to
         * collection" entry. Omitted from the deck-list popover (which has
         * no need for the bulk-add modal).
         */
        containers?: DeckActionsContainer[];
    }>(),
    { isOwner: true }
);
const { t } = useI18n();
const popoverId = useId();
const showCustomGroupsModal = ref(false);
const showCreateGroupModal = ref(false);
const showDeleteModal = ref(false);
const showAddAllToCollectionModal = ref(false);
const showGroupActions = computed(
    () => props.cards !== undefined && props.categories !== undefined && props.categoryNameMax !== undefined
);
const deleteTarget = computed<DeleteDeckTarget>(() => ({
    id: props.deck.id,
    name: props.deck.name,
    cardCount: props.deck.card_count,
    hasCompanion: props.deck.has_companion,
    hasDescription: props.deck.has_description,
    hasImage: props.deck.has_image
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
/** Open the bulk "Add all cards to collection" modal and close the popover. */
function openAddAllToCollection(): void {
    closePopover();
    showAddAllToCollectionModal.value = true;
}
/** Navigate to the deck-settings edit page. */
function onEditSettings(): void {
    closePopover();
    router.visit(`/decks/${props.deck.id}/edit`);
}
/** Navigate to the QR code page for this deck. */
function onQrClick(): void {
    closePopover();
    router.visit(`/decks/${props.deck.id}/qr`);
}
/**
 * Trigger the CSV export by navigating to the streaming endpoint. The
 * server returns Content-Disposition: attachment, so the browser starts
 * a download instead of a page navigation. We use a real link click so
 * the download lands on the same tab without an Inertia round-trip.
 */
function onExportClick(): void {
    closePopover();
    window.location.href = `/decks/${props.deck.id}/export`;
}
/**
 * Flip the deck between private and public via the dedicated quick-toggle
 * endpoint. The controller redirects back to the deck show page with a
 * flash message, so no client-side success handling is needed.
 */
function onToggleVisibility(): void {
    closePopover();
    const next = props.deck.visibility === "private" ? "public" : "private";
    router.patch(`/decks/${props.deck.id}/visibility`, { visibility: next }, { preserveScroll: true });
}
/**
 * "Set to finished" handler. Mode A patches state directly because the
 * wizard has nothing to claim; modes B and C open the wizard so the
 * user can claim physical copies before the transition. Only reachable
 * when `collectionMode` is set — the button is hidden otherwise.
 */
function onSetBuilt(): void {
    closePopover();
    if (props.collectionMode === "A" || props.collectionMode === undefined) {
        router.patch(`/decks/${props.deck.id}/state`, { state: "built" }, { preserveScroll: true });
        return;
    }
    router.visit(`/decks/${props.deck.id}/finalize`);
}
/**
 * "Set to planned" handler — pure built→planned state flip. Pivot rows
 * and `decks.container_id` are deliberately preserved (state and
 * collection_mode are orthogonal: claims survive across state changes).
 * Users who want a clean slate can clear claims via the deck-header
 * collection-mode modal's "Clear all collection assignments" action.
 */
function onSetPlanned(): void {
    closePopover();
    router.patch(`/decks/${props.deck.id}/state`, { state: "planned" }, { preserveScroll: true });
}
/**
 * Delete button handler. Skips the confirm prompt for an effectively-empty
 * deck and fires the DELETE directly. Same UX as the deck-list link.
 *
 * Forces an explicit visit to /decks on success because the server's
 * 303-to-/decks redirect is not always followed by Inertia v3's client
 * when issued from the deck-show page.
 */
function onDeleteClick(): void {
    closePopover();
    if (hasDeletableContent(deleteTarget.value)) {
        showDeleteModal.value = true;
        return;
    }
    router.delete(`/decks/${props.deck.id}`, {
        onSuccess: () => router.visit("/decks")
    });
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
            <li v-if="isOwner">
                <button class="popover-list-item" @click.prevent="onEditSettings">
                    <icon name="edit" :size="1" />
                    {{ $t("pages.create_deck.edit_link") }}
                </button>
            </li>
            <li v-if="isOwner">
                <button class="popover-list-item" @click.prevent="onToggleVisibility">
                    <icon :name="deck.visibility === 'private' ? 'visibility-on' : 'visibility-off'" :size="1" />
                    {{
                        $t(
                            deck.visibility === "private"
                                ? "pages.decks.actions.set_public"
                                : "pages.decks.actions.set_private"
                        )
                    }}
                </button>
            </li>
            <li v-if="isOwner && collectionMode !== undefined && deck.state === 'planned'">
                <button class="popover-list-item" @click.prevent="onSetBuilt">
                    <icon name="finished" :size="1" />
                    {{ $t("pages.decks.actions.set_built") }}
                </button>
            </li>
            <li v-else-if="isOwner && collectionMode !== undefined && deck.state === 'built'">
                <button class="popover-list-item" @click.prevent="onSetPlanned">
                    <icon name="planned" :size="1" />
                    {{ $t("pages.decks.actions.set_planned") }}
                </button>
            </li>
            <li v-if="isOwner && showGroupActions">
                <button class="popover-list-item" @click.prevent="openCreateGroup">
                    <icon name="add" :size="1" />
                    {{ $t("pages.deck.create_group.link") }}
                </button>
            </li>
            <li v-if="isOwner && showGroupActions">
                <button class="popover-list-item" @click.prevent="openCustomGroups">
                    <icon name="edit" :size="1" />
                    {{ $t("pages.deck.custom_groups.link") }}
                </button>
            </li>
            <li v-if="isOwner && containers !== undefined && deck.state !== 'built'">
                <button class="popover-list-item" @click.prevent="openAddAllToCollection">
                    <icon name="add-all" :size="1" />
                    {{ $t("pages.deck.add_all_to_collection.link") }}
                </button>
            </li>
            <li v-if="isOwner">
                <button class="popover-list-item" @click.prevent="onQrClick">
                    <icon name="qr-code" :size="1" />
                    {{ $t("pages.deck_qr.link") }}
                </button>
            </li>
            <li>
                <button class="popover-list-item" @click.prevent="onExportClick">
                    <icon name="download" :size="1" />
                    {{ $t("pages.decks.actions.export") }}
                </button>
            </li>
            <li v-if="isOwner">
                <button class="popover-list-item popover-list-item--error" @click.prevent="onDeleteClick">
                    <icon name="delete" :size="1" />
                    {{ $t("pages.decks.actions.delete") }}
                </button>
            </li>
        </ul>
    </pop-over>
    <deck-custom-groups-modal
        v-if="showCustomGroupsModal && showGroupActions"
        :deck-id="props.deck.id"
        :cards="props.cards!"
        :categories="props.categories!"
        :category-name-max="props.categoryNameMax!"
        @close="showCustomGroupsModal = false"
    />
    <deck-add-group-modal
        v-if="showCreateGroupModal && showGroupActions"
        :deck-id="props.deck.id"
        :category-name-max="props.categoryNameMax!"
        @close="showCreateGroupModal = false"
    />
    <delete-deck-modal v-if="showDeleteModal" :target="deleteTarget" @close="showDeleteModal = false" />
    <add-all-to-collection-modal
        v-if="showAddAllToCollectionModal && containers !== undefined"
        :deck-id="props.deck.id"
        :containers="containers"
        @close="showAddAllToCollectionModal = false"
    />
</template>
