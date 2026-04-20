<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import CardFaceImage from "Components/Card/CardFaceImage.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import Switch from "Components/Form/Switch.vue";
import Modal from "Components/Modal/Modal.vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import type { DeckCardDefaultCard, DeckCardRow } from "Types/deckPage";
import type { DefaultCardImage } from "Types/defaultCardImage";
/** A printing of the deck card's oracle card, plus whether the user owns a copy in a non-deckbox container and whether it's the current printing. */
type Printing = DefaultCardImage & { in_collection: boolean; is_current: boolean };
const emit = defineEmits<{ close: [] }>();
const props = defineProps<{
    /** UUID of the deck this card belongs to. */
    deckId: string;
    /** UUID of the deck card entry. */
    cardId: string;
    /** Card name, interpolated into the modal title. */
    name: string;
}>();
/** True while the printings XHR is in flight. */
const loading = ref(true);
/** True when the printings fetch failed. */
const error = ref(false);
/** Printings returned by the API. */
const printings = ref<Printing[]>([]);
/** When true, only printings the user owns in a non-deckbox container are shown. */
const onlyCollection = ref(false);
/** Printings filtered by the current `onlyCollection` toggle. */
const visiblePrintings = computed<Printing[]>(() =>
    onlyCollection.value ? printings.value.filter(p => p.in_collection) : printings.value
);
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
const page = usePage();
/**
 * Optimistically swap the deck card's printing: update the page's card in
 * place so the UI reflects the change immediately, then PATCH the server.
 * On failure, restore the previous printing.
 */
async function switchPrinting(printing: Printing): Promise<void> {
    if (printing.is_current) return;
    printings.value.forEach(p => (p.is_current = p.id === printing.id));
    const cards = page.props.cards as DeckCardRow[];
    const card = cards.find(c => c.id === props.cardId);
    if (!card) {
        emit("close");
        return;
    }
    const previous: DeckCardDefaultCard = { ...card.default_card };
    card.default_card = {
        id: printing.id,
        name: printing.name,
        card_image_0: printing.card_image_0,
        card_image_1: printing.card_image_1,
        set: printing.set ? { name: printing.set.name, code: printing.set.code } : null
    };
    emit("close");
    const response = await fetch(`/api/decks/${props.deckId}/cards/${props.cardId}/printing`, {
        method: "PATCH",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": page.props.csrfToken as string,
            Accept: "application/json"
        },
        body: JSON.stringify({ default_card_id: printing.id })
    });
    if (!response.ok) {
        const target = cards.find(c => c.id === props.cardId);
        if (target) target.default_card = previous;
    }
}
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
        <form class="form">
            <form-group class="switch-label">
                <Switch
                    ref-id="only_collection_printings"
                    :label="$t('pages.deck.switch_printing.only_collection_printings')"
                    :checked-initially="onlyCollection"
                    @change="onlyCollection = $event"
                />
                {{ $t("pages.deck.switch_printing.only_collection_printings") }}
            </form-group>
        </form>
        <div v-if="loading" class="switch-printing__loading">
            <loading-spinner :size="4" :branded="true" />
            <p>{{ $t("pages.deck.switch_printing.loading") }}</p>
        </div>
        <div v-else-if="error" class="switch-printing__error">
            <icon name="error" :size="2" />
            <p>{{ $t("pages.deck.switch_printing.error") }}</p>
        </div>
        <div v-else class="switch-printing__list">
            <button
                v-for="printing in visiblePrintings"
                type="button"
                :key="printing.id"
                :class="{ 'switch-printing__list-item--current': printing.is_current }"
                @click="switchPrinting(printing)"
            >
                <card-face-image :card="printing" tooltip-container="#modal-body" />
                <span
                    v-if="printing.is_current"
                    class="switch-printing__current-badge"
                    :aria-label="$t('pages.deck.switch_printing.currently_selected')"
                >
                    <icon name="check" :size="3" />
                    {{ $t("pages.deck.switch_printing.currently_selected") }}
                </span>
                <!--                <span v-if="printing.in_collection">{{ $t("pages.deck.switch_printing.in_collection") }}</span>-->
                <!--                <span v-else>{{ $t("pages.deck.switch_printing.not_in_collection") }}</span>-->
            </button>
        </div>
    </modal>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/shadows" as sh;
@use "Abstracts/sizes" as s;

.switch-label:deep(.form-group__field) {
    display: flex;
    align-items: center;

    gap: 1rem;
}

.switch-printing {
    &__list {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));

        gap: 1rem;

        > button {
            display: flex;
            position: relative;
            flex-direction: column;

            padding: 0;
            border: 0;
            gap: 0.5rem;

            background-color: transparent;

            &:hover .face-image {
                box-shadow: map.get(sh.$pages, "deck", "switch-printing-hover");

                cursor: pointer;
            }
        }
    }

    &__current-badge {
        display: flex;
        position: absolute;
        top: 50%;
        left: 50%;
        align-items: center;

        padding: map.get(s.$pages, "deck", "switch-printing", "current", "padding");
        gap: map.get(s.$pages, "deck", "switch-printing", "current", "gap");

        transform: translateX(-50%);

        background: map.get(c.$state, "success", "background");
        color: map.get(c.$state, "success", "surface");
        border-radius: map.get(s.$pages, "deck", "switch-printing", "current", "radius");
    }
}
</style>
