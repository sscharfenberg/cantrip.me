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
    /** Current companion name — interpolated into the switch-printing modal title. */
    companionName: string;
    /** Whether this sits on top of a card image (tweaks PopOver trigger size). */
    isMediumButton?: boolean;
}>();
const popoverId = useId();
const page = usePage();
const removing = ref(false);
const showSwitchPrintingModal = ref(false);
function closePopover(): void {
    const el = document.getElementById(popoverId);
    if (el !== null) el.hidePopover();
}
function openSwitchPrinting(): void {
    closePopover();
    showSwitchPrintingModal.value = true;
}
async function removeCompanion(): Promise<void> {
    if (removing.value) return;
    removing.value = true;
    closePopover();
    const response = await fetch(`/api/decks/${props.deckId}/companion`, {
        method: "DELETE",
        headers: {
            "X-CSRF-TOKEN": page.props.csrfToken as string,
            Accept: "application/json",
        },
    });
    if (!response.ok) {
        removing.value = false;
        return;
    }
    router.reload({
        only: ["deck", "companion"],
        onFinish: () => {
            removing.value = false;
        },
    });
}
async function switchPrinting(printing: DeckPrinting): Promise<void> {
    const response = await fetch(`/api/decks/${props.deckId}/companion/printing`, {
        method: "PATCH",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": page.props.csrfToken as string,
            Accept: "application/json",
        },
        body: JSON.stringify({ default_card_id: printing.id }),
    });
    if (response.ok) {
        router.reload({ only: ["deck", "companion"] });
    }
}
</script>

<template>
    <pop-over
        icon="more"
        aria-label="Companion actions"
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
            <li>
                <button
                    type="button"
                    class="popover-list-item popover-list-item--error"
                    :disabled="removing"
                    @click="removeCompanion"
                >
                    <icon name="delete" :size="1" />
                    {{ $t("pages.deck.companion.remove") }}
                </button>
            </li>
        </ul>
    </pop-over>
    <deck-card-switch-printing-modal
        v-if="showSwitchPrintingModal"
        :printings-url="`/api/decks/${props.deckId}/companion/printings`"
        :name="props.companionName"
        @select="switchPrinting"
        @close="showSwitchPrintingModal = false"
    />
</template>