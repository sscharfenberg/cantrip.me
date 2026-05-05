<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { computed, ref, useId } from "vue";
import { useI18n } from "vue-i18n";
import DeleteDeckModal from "@/pages/Deck/Modals/DeleteDeckModal.vue";
import type { DeckRow } from "@/pages/Decks/Decks.vue";
import { hasDeletableContent } from "@/utils/deleteDeck";
import type { DeleteDeckTarget } from "@/utils/deleteDeck";
import ColorIdentity from "Components/Card/ColorIdentity.vue";
import DeckState from "Components/Deck/DeckState.vue";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import VisibilityBadge from "Components/UI/VisibilityBadge.vue";
import { useFormatting } from "Composables/useFormatting.ts";
const props = defineProps<{
    /** A single deck row from the controller. */
    deck: DeckRow;
}>();
const { t } = useI18n();
const { formatDateTime } = useFormatting();
const popoverId = useId();
/** Controls the confirm-delete modal. */
const showDeleteModal = ref(false);
/** Adapter from this page's `DeckRow` shape to the modal's `DeleteDeckTarget`. */
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
/**
 * Navigate to the edit-settings page. Programmatic visit instead of a
 * nested `<Link>` because the entire row is wrapped in an Inertia
 * `<Link>` already and an `<a>` inside another `<a>` is invalid HTML.
 */
function onEditClick(): void {
    closePopover();
    router.visit(`/decks/${props.deck.id}/edit`);
}
/**
 * Navigate to the QR code page for this deck. Same programmatic-visit
 * rationale as `onEditClick` — the row is already wrapped in a `<Link>`.
 */
function onQrClick(): void {
    closePopover();
    router.visit(`/decks/${props.deck.id}/qr`);
}
/**
 * Delete button handler. Skips the confirm prompt entirely for "empty"
 * decks — no cards, no companion, no description, no custom image — and
 * fires the DELETE directly. Anything worth losing opens the modal first.
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
    <Link class="decklist__link" :href="`/decks/${deck.id}`">
        <color-identity :color-identity="deck.colors" />
        <span class="decklist__name">{{ deck.name }}</span>
        <deck-state :state="deck.state" />
        <span class="decklist__cards">
            <icon name="deck" />
            {{ deck.card_count }}
            <span>{{ t("pages.decks.card_count_noun", deck.card_count) }}</span>
        </span>
        <badge
            v-tooltip="formatDateTime(deck.last_activity)"
            class="decklist__timestamp"
            type="info"
        >
            <icon name="calendar" :size="1" />
        </badge>
        <badge
            v-if="deck.bracket"
            v-tooltip="t('form.fields.deck_bracket_hint')"
            class="deck-bracket"
            type="info"
        >
            <icon name="swords" :size="1" />
            <span>{{ deck.bracket }}</span>
        </badge>
        <visibility-badge :visibility="deck.visibility" />
        <pop-over
            icon="more"
            :aria-label="t('pages.decks.actions.label')"
            class-string="popover-button--rounded"
            :reference="popoverId"
            width="14rem"
        >
            <ul class="popover-list">
                <li>
                    <button class="popover-list-item" @click.prevent="onEditClick">
                        <icon name="edit" :size="1" />
                        {{ t("pages.create_deck.edit_link") }}
                    </button>
                </li>
                <li>
                    <button class="popover-list-item" @click.prevent="onQrClick">
                        <icon name="qr-code" :size="1" />
                        {{ t("pages.deck_qr.link") }}
                    </button>
                </li>
                <li>
                    <button class="popover-list-item popover-list-item--error" @click.prevent="onDeleteClick">
                        <icon name="delete" :size="1" />
                        {{ t("pages.decks.actions.delete") }}
                    </button>
                </li>
            </ul>
        </pop-over>
    </Link>
    <delete-deck-modal v-if="showDeleteModal" :target="deleteTarget" @close="showDeleteModal = false" />
</template>

<style lang="scss" scoped>
/** styles can be found in
 * resources/app/styles/components/deck/_decklist.scss
 */
@use "Abstracts/mixins" as m;

:deep(.visibility-badge) {
    display: none;

    @include m.mq("landscape") {
        display: inline-flex;
    }
}

.deck-bracket {
    display: none;

    @include m.mq("landscape") {
        display: inline-flex;
    }
}

:deep(.popover) {
    justify-self: end;
}

:deep(.deck-state) {
    font-size: 0.8em;

    span {
        display: none;

        @include m.mq("landscape") {
            display: inline-flex;
        }
    }
}
</style>
