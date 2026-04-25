<script setup lang="ts">
import { ref, useId } from "vue";
import DeckCardMoveToGroupModal from "@/pages/Decks/Deck/Modals/DeckCardMoveToGroupModal.vue";
import DeckCardSplitPrintingModal from "@/pages/Decks/Deck/Modals/DeckCardSplitPrintingModal.vue";
import DeckCardSwitchPrintingModal from "@/pages/Decks/Deck/Modals/DeckCardSwitchPrintingModal.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import { useDeckCardActions } from "Composables/useDeckCardActions.ts";
import type { DeckCardRow, DeckCategoryRow } from "Types/deckPage";
const props = defineProps<{
    /** UUID of the deck this card belongs to. */
    deckId: string;
    /** The full deck card row — forwarded into the move-to-group modal. */
    card: DeckCardRow;
    /** All deck card rows — passed through so the copy-limit check sums siblings. */
    cards: DeckCardRow[];
    /** User-defined categories for the deck — listed as move targets. */
    categories: DeckCategoryRow[];
    /** Maximum length for a category name — forwarded to the create-group modal. */
    categoryNameMax: number;
    /** Maximum copies allowed by the format (e.g. 4 for constructed, 1 for singleton). */
    maxCopies: number;
    /** Whether the format is singleton (max 1 copy of non-basic cards). */
    isSingleton: boolean;
    /** whether the button should be "medium" or not */
    isMediumButton?: boolean;
}>();
const popoverId = useId();
/** Controls visibility of the switch-printing modal. */
const showSwitchPrintingModal = ref(false);
/** Controls visibility of the split-printing modal. */
const showSplitPrintingModal = ref(false);
/** Controls visibility of the move-to-group modal. */
const showMoveToGroupModal = ref(false);
/** Close the action popover programmatically. */
function closePopover(): void {
    const el = document.getElementById(popoverId);
    if (el !== null) el.hidePopover();
}
/** Close the popover and open the switch-printing modal. */
function openSwitchPrinting(): void {
    closePopover();
    showSwitchPrintingModal.value = true;
}
/** Close the popover and open the split-printing modal. */
function openSplitPrinting(): void {
    closePopover();
    showSplitPrintingModal.value = true;
}
/** Close the popover and open the move-to-group modal. */
function openMoveToGroup(): void {
    closePopover();
    showMoveToGroupModal.value = true;
}
const { canIncrement, increment, decrement, destroy, moveZone, switchPrinting } = useDeckCardActions(
    {
        deckId: props.deckId,
        cardId: props.card.id,
        oracleCardId: props.card.oracle_card_id,
        quantity: () => props.card.quantity,
        cards: () => props.cards,
        isBasicLand: props.card.is_basic_land,
        isUnlimited: props.card.is_unlimited,
        maxCopies: props.maxCopies,
        isSingleton: props.isSingleton
    },
    closePopover
);
</script>

<template>
    <pop-over
        icon="more"
        aria-label="Actions"
        :class-string="`popover-button--rounded${props.isMediumButton ? '' : ' popover-button--tiny'}`"
        :reference="popoverId"
        width="14rem"
    >
        <ul class="popover-list">
            <li class="popover-list-multi">
                <button
                    type="button"
                    class="popover-list-item"
                    @click="decrement"
                    :aria-label="$t('pages.deck.card_quantity.increment')"
                >
                    <icon name="subtract" :size="1" />
                </button>
                <button
                    type="button"
                    class="popover-list-item"
                    :disabled="!canIncrement"
                    @click="increment"
                    :aria-label="$t('pages.deck.card_quantity.decrement')"
                >
                    <icon name="add" :size="1" />
                </button>
            </li>
            <li>
                <button type="button" class="popover-list-item" @click="openSwitchPrinting">
                    <icon name="card" :size="1" />
                    {{ $t("pages.deck.switch_printing.link") }}
                </button>
            </li>
            <li v-if="props.card.quantity > 1">
                <button type="button" class="popover-list-item" @click="openSplitPrinting">
                    <icon name="copy" :size="1" />
                    {{ $t("pages.deck.split_printing.link") }}
                </button>
            </li>
            <li v-if="props.card.zone !== 'side'">
                <button type="button" class="popover-list-item" @click="openMoveToGroup">
                    <icon name="cards" :size="1" />
                    {{ $t("pages.deck.move_to_group.link") }}
                </button>
            </li>
            <li v-if="props.card.zone !== 'side'">
                <button type="button" class="popover-list-item" @click="moveZone('side')">
                    <icon name="sideboard" :size="1" />
                    {{ $t("pages.deck.move_zone.to_side") }}
                </button>
            </li>
            <li v-else>
                <button type="button" class="popover-list-item" @click="moveZone('main')">
                    <icon name="deck" :size="1" />
                    {{ $t("pages.deck.move_zone.to_main") }}
                </button>
            </li>
            <li>
                <button type="button" class="popover-list-item popover-list-item--error" @click="destroy">
                    <icon name="delete" :size="1" />
                    {{ $t("pages.deck.card_quantity.destroy") }}
                </button>
            </li>
        </ul>
    </pop-over>
    <deck-card-switch-printing-modal
        v-if="showSwitchPrintingModal"
        :printings-url="`/api/decks/${props.deckId}/cards/${props.card.id}/printings`"
        :name="props.card.name"
        @select="switchPrinting"
        @close="showSwitchPrintingModal = false"
    />
    <deck-card-split-printing-modal
        v-if="showSplitPrintingModal"
        :deck-id="props.deckId"
        :card-id="props.card.id"
        :name="props.card.name"
        :quantity="props.card.quantity"
        @close="showSplitPrintingModal = false"
    />
    <deck-card-move-to-group-modal
        v-if="showMoveToGroupModal"
        :deck-id="props.deckId"
        :card="props.card"
        :categories="props.categories"
        :category-name-max="props.categoryNameMax"
        @close="showMoveToGroupModal = false"
    />
</template>

<style lang="scss" scoped>
.popover-list-multi {
    display: flex;

    gap: 0.5rem;

    > button {
        display: flex;
        align-items: center;
        justify-content: center;

        &:disabled {
            opacity: 0.35;

            cursor: not-allowed;
        }
    }
}
</style>
