<script setup lang="ts">
import { ref } from "vue";
import DeckHeroImagePickerModal from "@/pages/Deck/Modals/DeckHeroImagePickerModal.vue";
import type { DeckHeroCardOption } from "@/pages/Deck/Modals/DeckHeroImagePickerModal.vue";
import ArtCropImage from "Components/Card/ArtCropImage.vue";
import FormGroup from "Components/Form/FormGroup.vue";
import Icon from "Components/UI/Icon.vue";

const props = defineProps<{
    /** UUID of the deck whose hero image is being picked. */
    deckId: string;
    /**
     * Card options to show in the picker. The deck-edit page already has
     * the full deck-card list, so it passes them through to skip a server
     * round-trip when the modal opens.
     */
    cards: DeckHeroCardOption[];
    /** Two-way bound currently selected hero card (null = none). */
    modelValue: DeckHeroCardOption | null;
}>();

const emit = defineEmits<{
    /** Two-way binding for `modelValue`. Parent owns the ref. */
    "update:modelValue": [card: DeckHeroCardOption | null];
}>();

const showModal = ref(false);

function onSelect(card: DeckHeroCardOption): void {
    emit("update:modelValue", card);
}
</script>

<template>
    <div>
        <form-group :label="$t('pages.create_deck.hero_image.label')">
            <div v-if="props.modelValue" class="hero-image-picker__current">
                <art-crop-image :card="props.modelValue" />
                <button type="button" class="btn-default" @click="showModal = true">
                    <icon name="card" />
                    {{ $t("pages.create_deck.hero_image.change") }}
                </button>
            </div>
            <button v-else type="button" class="btn-default" @click="showModal = true">
                <icon name="card" />
                {{ $t("pages.create_deck.hero_image.select") }}
            </button>
        </form-group>
        <deck-hero-image-picker-modal
            v-if="showModal"
            :deck-id="deckId"
            :cards="cards"
            @select="onSelect"
            @close="showModal = false"
        />
    </div>
</template>

<style lang="scss" scoped>
.hero-image-picker__current {
    display: flex;
    align-items: flex-start;
    flex-direction: column;

    gap: 0.5rem;

    .art-crop {
        max-width: 320px;
    }
}
</style>
