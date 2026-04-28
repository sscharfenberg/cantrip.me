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
    /** True when the request user owns the deck — gates the actions menu. */
    isOwner: boolean;
    /** Current deck hero printing id, or null — forwarded into the actions menu. */
    heroCardId: string | null;
}>();
</script>

<template>
    <ul v-if="variant === 'image'" class="image-card-group__list">
        <face-image-lazy
            :card-image0="companion.default_card.card_image_0"
            :card-image1="companion.default_card.card_image_1"
            :name="companion.name"
        >
            <deck-companion-actions-menu
                v-if="isOwner"
                :deck-id="deckId"
                :companion-name="companion.name"
                :default-card-id="companion.default_card.id"
                :hero-card-id="heroCardId"
                :is-medium-button="true"
            />
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
            <mana-cost :mana-cost="companion.mana_cost" />
            <deck-companion-actions-menu
                v-if="isOwner"
                :deck-id="deckId"
                :companion-name="companion.name"
                :default-card-id="companion.default_card.id"
                :hero-card-id="heroCardId"
            />
        </li>
    </ul>
</template>
