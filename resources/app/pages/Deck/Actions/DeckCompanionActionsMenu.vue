<script setup lang="ts">
import { Link, router, usePage } from "@inertiajs/vue3";
import { ref, useId } from "vue";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import type { DeckPrinting } from "Types/defaultCardImage.ts";
import DeckCardSwitchPrintingModal from "../Modals/DeckCardSwitchPrintingModal.vue";
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
 * Remove the companion from the deck. `removing` guards against double-
 * submits while the DELETE is in flight; cleared once the Inertia reload
 * finishes so the button re-enables only after the UI reflects the new state.
 */
async function removeCompanion(): Promise<void> {
    if (removing.value) return;
    removing.value = true;
    closePopover();
    const response = await fetch(`/api/decks/${props.deckId}/companion`, {
        method: "DELETE",
        headers: {
            "X-CSRF-TOKEN": page.props.csrfToken as string,
            Accept: "application/json"
        }
    });
    if (!response.ok) {
        removing.value = false;
        return;
    }
    router.reload({
        only: ["deck", "companion"],
        onFinish: () => {
            removing.value = false;
        }
    });
}
/** Swap the companion's display printing (its `default_card_id`). */
async function switchPrinting(printing: DeckPrinting): Promise<void> {
    const response = await fetch(`/api/decks/${props.deckId}/companion/printing`, {
        method: "PATCH",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": page.props.csrfToken as string,
            Accept: "application/json"
        },
        body: JSON.stringify({ default_card_id: printing.id })
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
                <Link
                    :href="`/decks/${props.deckId}/companion/use-as-hero`"
                    method="patch"
                    as="button"
                    class="popover-list-item"
                    @click="closePopover"
                >
                    <icon name="container-image" :size="1" />
                    {{ $t("pages.deck.use_as_hero.link") }}
                </Link>
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
