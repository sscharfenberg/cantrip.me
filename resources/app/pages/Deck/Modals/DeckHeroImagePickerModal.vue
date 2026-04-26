<script setup lang="ts">
import { onMounted, ref } from "vue";
import ArtCropImage from "Components/Card/ArtCropImage.vue";
import FormLegend from "Components/Form/FormLegend.vue";
import Modal from "Components/Modal/Modal.vue";
import type { DefaultCardArtCrop } from "Types/defaultCardArtCrop.ts";

/**
 * Re-exported under a domain-specific name so the rest of the deck-edit
 * code refers to "hero card options" instead of the generic art-crop
 * shape; the underlying type is identical.
 */
export type DeckHeroCardOption = DefaultCardArtCrop;

const emit = defineEmits<{
    close: [];
    /** Fired when the user picks a card; the caller owns persistence + UI state. */
    select: [card: DeckHeroCardOption];
}>();
const props = defineProps<{
    /** UUID of the deck — used to fetch cards if `cards` isn't provided. */
    deckId: string;
    /**
     * Card options the user can pick from. When omitted the modal falls
     * back to fetching them from the server on mount, so the picker can
     * also be opened from contexts that don't already carry the deck list.
     */
    cards?: DeckHeroCardOption[] | null;
}>();

/** Cards rendered in the picker — either the prop or the fetched list. */
const cards = ref<DeckHeroCardOption[]>(props.cards ?? []);
/** True while the cards fetch is in flight (only the fallback path). */
const loading = ref(false);

onMounted(async () => {
    if (props.cards !== undefined && props.cards !== null) return;
    loading.value = true;
    try {
        const response = await fetch(`/api/decks/${props.deckId}/hero-image-options`, {
            headers: { Accept: "application/json" },
        });
        if (response.ok) {
            cards.value = (await response.json()) as DeckHeroCardOption[];
        }
    } finally {
        loading.value = false;
    }
});

/** Emit the selected card and close the modal. Caller owns persistence. */
function pick(card: DeckHeroCardOption): void {
    emit("select", card);
    emit("close");
}
</script>

<template>
    <modal @close="emit('close')">
        <template #header>{{ $t("pages.create_deck.hero_image.modal_title") }}</template>
        <form-legend :items="[{ slot: 'explanation', icon: 'container-image' }]">
            <template #explanation>{{ $t("pages.create_deck.hero_image.explanation") }}</template>
        </form-legend>
        <p v-if="loading">{{ $t("pages.create_deck.hero_image.loading") }}</p>
        <ul v-else class="hero-image-picker">
            <li v-for="card in cards" :key="card.id" class="hero-image-picker__item">
                <button type="button" class="hero-image-picker__button" @click="pick(card)">
                    <art-crop-image :card="card" :interactive="true" />
                </button>
            </li>
        </ul>
    </modal>
</template>

<style lang="scss" scoped>
.hero-image-picker {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(220px, 100%), 1fr));

    padding: 0;
    margin: 1rem 0 0;
    gap: 0.75rem;

    list-style: none;

    &__button {
        display: block;

        width: 100%;
        padding: 0;
        border: 0;

        background: transparent;

        cursor: pointer;
    }
}
</style>