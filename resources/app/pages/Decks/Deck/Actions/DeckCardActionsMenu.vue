<script setup lang="ts">
import { ref, useId } from "vue";
import DeckCardSplitPrintingModal from "@/pages/Decks/Deck/Modals/DeckCardSplitPrintingModal.vue";
import DeckCardSwitchPrintingModal from "@/pages/Decks/Deck/Modals/DeckCardSwitchPrintingModal.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import { useDeckCardActions } from "Composables/useDeckCardActions.ts";
const props = defineProps<{
    /** UUID of the deck this card belongs to. */
    deckId: string;
    /** UUID of the deck card entry. */
    cardId: string;
    /** Card name, shown in the switch-printing modal title. */
    name: string;
    /** Current number of copies (from server). */
    quantity: number;
    /** Whether this card is a basic land (exempt from copy limits). */
    isBasicLand: boolean;
    /** Maximum copies allowed by the format (e.g. 4 for constructed, 1 for singleton). */
    maxCopies: number;
    /** Whether the format is singleton (max 1 copy of non-basic cards). */
    isSingleton: boolean;
}>();
const popoverId = useId();
/** Controls visibility of the switch-printing modal. */
const showSwitchPrintingModal = ref(false);
/** Controls visibility of the split-printing modal. */
const showSplitPrintingModal = ref(false);
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
const { canIncrement, increment, decrement, destroy } = useDeckCardActions(
    {
        deckId: props.deckId,
        cardId: props.cardId,
        quantity: () => props.quantity,
        isBasicLand: props.isBasicLand,
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
        class-string="popover-button--rounded popover-button--tiny"
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
            <li v-if="props.quantity > 1">
                <button type="button" class="popover-list-item" @click="openSplitPrinting">
                    <icon name="copy" :size="1" />
                    {{ $t("pages.deck.split_printing.link") }}
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
        :deck-id="props.deckId"
        :card-id="props.cardId"
        :name="props.name"
        @close="showSwitchPrintingModal = false"
    />
    <deck-card-split-printing-modal
        v-if="showSplitPrintingModal"
        :deck-id="props.deckId"
        :card-id="props.cardId"
        :name="props.name"
        :quantity="props.quantity"
        @close="showSplitPrintingModal = false"
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
