<script setup lang="ts">
import CardImagePreview from "Components/Card/CardImagePreview.vue";
import ManaCost from "Components/Card/ManaCost.vue";
import CollectionImplicitBadge from "Components/Deck/CollectionImplicitBadge.vue";
import CollectionStatusBadge from "Components/Deck/CollectionStatusBadge.vue";
import type { DeckCompanion } from "Types/deckPage.ts";
import DeckCompanionActionsMenu from "../Actions/DeckCompanionActionsMenu.vue";
import FaceImageLazy from "../Cards/FaceImageLazy.vue";
const emit = defineEmits<{ preview: [defaultCardId: string] }>();
defineProps<{
    deckId: string;
    companion: DeckCompanion;
    /** Which card view this is rendered in — picks the tile presentation. */
    variant: "image" | "text";
    /** True when the request user owns the deck — gates the actions menu. */
    isOwner: boolean;
    /** Current deck hero printing id, or null — forwarded into the actions menu. */
    heroCardId: string | null;
    /** Effective collection-integration mode — drives which badge renders. */
    collectionMode: "A" | "B" | "C";
}>();
</script>

<template>
    <ul v-if="variant === 'image'" class="image-card-group__list">
        <face-image-lazy
            :card-image0="companion.default_card.card_image_0"
            :card-image1="companion.default_card.card_image_1"
            :name="companion.name"
            @preview="emit('preview', companion.default_card.id)"
        >
            <collection-status-badge
                v-if="collectionMode === 'C' && companion.collection_status"
                :status="companion.collection_status"
                variant="corner"
            />
            <collection-implicit-badge
                v-if="collectionMode === 'B' && companion.collection_implicit_status"
                :status="companion.collection_implicit_status"
                :quantity="1"
                variant="corner"
            />
            <deck-companion-actions-menu
                v-if="isOwner"
                :deck-id="deckId"
                :deck-card-id="companion.deck_card_id"
                :companion-name="companion.name"
                :default-card-id="companion.default_card.id"
                :hero-card-id="heroCardId"
                :collection-mode="collectionMode"
                :is-medium-button="true"
            />
        </face-image-lazy>
    </ul>
    <ul v-else class="text-card-group__list">
        <li class="card">
            <card-image-preview
                :src="companion.default_card.card_image_0"
                :alt="companion.name"
                @preview="emit('preview', companion.default_card.id)"
            >
                <span class="card__qty">1x </span>{{ companion.name }}
            </card-image-preview>
            <collection-status-badge
                v-if="collectionMode === 'C' && companion.collection_status"
                :status="companion.collection_status"
                variant="inline"
            />
            <collection-implicit-badge
                v-if="collectionMode === 'B' && companion.collection_implicit_status"
                :status="companion.collection_implicit_status"
                :quantity="1"
                variant="inline"
            />
            <mana-cost :mana-cost="companion.mana_cost" />
            <deck-companion-actions-menu
                v-if="isOwner"
                :deck-id="deckId"
                :deck-card-id="companion.deck_card_id"
                :companion-name="companion.name"
                :default-card-id="companion.default_card.id"
                :hero-card-id="heroCardId"
                :collection-mode="collectionMode"
            />
        </li>
    </ul>
</template>
