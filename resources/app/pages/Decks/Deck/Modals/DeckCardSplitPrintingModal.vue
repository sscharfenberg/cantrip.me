<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import type { DefaultCardImage } from "Types/defaultCardImage";
/** A printing of the deck card's oracle card, plus collection/current flags. */
type Printing = DefaultCardImage & { in_collection: boolean; is_current: boolean };
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    /** UUID of the deck this card belongs to. */
    deckId: string;
    /** UUID of the deck card entry. */
    cardId: string;
    /** Card name, interpolated into the modal title. */
    name: string;
    /** Current number of copies — split is only meaningful when > 1. */
    quantity: number;
}>();
/** True while the printings XHR is in flight. */
const loading = ref(true);
/** True when the printings fetch failed. */
const error = ref(false);
/** Printings returned by the API. */
const printings = ref<Printing[]>([]);
/** AbortController so the in-flight request is cancelled if the modal is unmounted. */
let abortController: AbortController | null = null;
onMounted(async () => {
    abortController = new AbortController();
    try {
        const response = await fetch(`/api/decks/${props.deckId}/cards/${props.cardId}/printings`, {
            headers: { Accept: "application/json" },
            signal: abortController.signal
        });
        if (response.ok) {
            printings.value = (await response.json()) as Printing[];
        } else {
            error.value = true;
        }
    } catch (e) {
        if (e instanceof DOMException && e.name === "AbortError") return;
        error.value = true;
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => {
    if (abortController) abortController.abort();
});
</script>

<template>
    <modal @close="emit('close')">
        <template #header>
            <i18n-t keypath="pages.deck.switch_printing.title" scope="global">
                <template #card
                    ><cite>{{ name }}</cite></template
                >
            </i18n-t>
        </template>
        <div v-if="loading" class="split-printing__loading">
            <loading-spinner :size="4" :branded="true" />
            <p>{{ $t("pages.deck.switch_printing.loading") }}</p>
        </div>
        <div v-else-if="error" class="split-printing__error">
            <icon name="error" :size="2" />
            <p>{{ $t("pages.deck.switch_printing.error") }}</p>
        </div>
        <div v-else>
            <!-- TODO: split printings UI — {{ printings.length }} printings, quantity {{ quantity }} -->
        </div>
    </modal>
</template>