<script setup lang="ts">
import CardImagePreview from "Components/Card/CardImagePreview.vue";
import ManaCost from "Components/Card/ManaCost.vue";
import type { DeckCompanion } from "Types/deckPage.ts";
import DeckCompanionActionsMenu from "../Actions/DeckCompanionActionsMenu.vue";
import FaceImageLazy from "../Cards/FaceImageLazy.vue";
interface PreviewTarget {
    name: string;
    cardImage0: string | null;
    cardImage1: string | null;
}
const emit = defineEmits<{ preview: [target: PreviewTarget] }>();
defineProps<{
    deckId: string;
    companion: DeckCompanion;
    /** Which card view this is rendered in — picks the tile presentation. */
    variant: "image" | "text";
}>();
</script>

<template>
    <ul v-if="variant === 'image'" class="image-card-group__list">
        <face-image-lazy
            :card-image0="companion.default_card.card_image_0"
            :card-image1="companion.default_card.card_image_1"
            :name="companion.name"
        >
            <deck-companion-actions-menu :deck-id="deckId" :companion-name="companion.name" :is-medium-button="true" />
        </face-image-lazy>
    </ul>
    <ul v-else class="text-card-group__list">
        <li class="card">
            <card-image-preview
                :src="companion.default_card.card_image_0"
                :alt="companion.name"
                @preview="
                    emit('preview', {
                        name: companion.name,
                        cardImage0: companion.default_card.card_image_0,
                        cardImage1: companion.default_card.card_image_1
                    })
                "
            >
                <span class="card__qty">1x </span>{{ companion.name }}
            </card-image-preview>
            <mana-cost v-for="(cost, i) in companion.mana_cost" :key="i" :mana-cost="cost" />
            <deck-companion-actions-menu :deck-id="deckId" :companion-name="companion.name" />
        </li>
    </ul>
</template>
