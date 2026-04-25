<script setup lang="ts">
import { router, usePage } from "@inertiajs/vue3";
import { ref, useId } from "vue";
import DeckCardSwitchPrintingModal from "@/pages/Decks/Deck/Modals/DeckCardSwitchPrintingModal.vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import type { DeckPrinting } from "Types/defaultCardImage";
const props = defineProps<{
    /** UUID of the deck. */
    deckId: string;
    /** UUID of the commander's oracle card — picks which pivot row is updated. */
    oracleCardId: string;
    /** Commander name — interpolated into the switch-printing modal title. */
    commanderName: string;
    /** Whether this sits on top of a card image (tweaks PopOver trigger size). */
    isMediumButton?: boolean;
}>();
const popoverId = useId();
const page = usePage();
const showSwitchPrintingModal = ref(false);
/** Dismiss the popover menu via the native popover API. */
function closePopover(): void {
    const el = document.getElementById(popoverId);
    if (el !== null) el.hidePopover();
}
/** Close the menu and open the switch-printing modal. */
function openSwitchPrinting(): void {
    closePopover();
    showSwitchPrintingModal.value = true;
}
/**
 * Swap this commander's display printing (the `commanders` pivot row's
 * `default_card_id`). Reload only `deck` and `commanders` so the new image
 * shows without dragging the rest of the page through a full refresh.
 */
async function switchPrinting(printing: DeckPrinting): Promise<void> {
    const response = await fetch(
        `/api/decks/${props.deckId}/commander/${props.oracleCardId}/printing`,
        {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": page.props.csrfToken as string,
                Accept: "application/json",
            },
            body: JSON.stringify({ default_card_id: printing.id }),
        },
    );
    if (response.ok) {
        router.reload({ only: ["deck", "commanders"] });
    }
}
</script>

<template>
    <pop-over
        icon="more"
        aria-label="Commander actions"
        :class-string="`popover-button--rounded${props.isMediumButton ? '' : ' popover-button--tiny'}`"
        :reference="popoverId"
        width="14rem"
    >
        <ul class="popover-list">
            <li>
                <button type="button" class="popover-list-item" @click="openSwitchPrinting">
                    <icon name="card" :size="1" />
                    {{ $t("pages.deck.switch_printing.link") }}
                </button>
            </li>
        </ul>
    </pop-over>
    <deck-card-switch-printing-modal
        v-if="showSwitchPrintingModal"
        :printings-url="`/api/decks/${props.deckId}/commander/${props.oracleCardId}/printings`"
        :name="props.commanderName"
        @select="switchPrinting"
        @close="showSwitchPrintingModal = false"
    />
</template>