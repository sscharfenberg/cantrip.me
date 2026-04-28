<script setup lang="ts">
import { ref } from "vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckCardRow, DeckCompanion, DeckMeta } from "Types/deckPage.ts";
import AddCompanionModal from "../Modals/AddCompanionModal.vue";
import CardAddModal from "../Modals/CardAddModal.vue";
import DeckNavigationQuickAdd from "./DeckNavigationQuickAdd.vue";
defineProps<{
    /** Deck metadata (name, format, state, colors, etc.). */
    deck: DeckMeta;
    /** All cards in the deck — forwarded for zone capacity checks. */
    cards: DeckCardRow[];
    /** Currently-set companion card, or null. */
    companion: DeckCompanion | null;
    /** The ten companion roster, shaped for the picker modal. */
    companionRoster: DeckCompanion[];
    /** Combined commander color identity, pre-unioned from the commanders list. */
    commanderColorIdentity: string;
}>();
/** Controls visibility of the full card-add modal. */
const showAddModal = ref(false);
/** Controls visibility of the companion picker. */
const showCompanionModal = ref(false);
</script>

<template>
    <div class="deck-navigation-add">
        <button type="button" class="btn-default" @click="showAddModal = true">
            <icon name="add" />
            {{ $t("pages.deck.add.label") }}
        </button>
        <button
            v-if="deck.allows_companion && !companion"
            type="button"
            class="btn-default"
            @click="showCompanionModal = true"
        >
            <icon name="add" />
            {{ $t("pages.deck.companion.add") }}
        </button>
        <deck-navigation-quick-add
            :deck-id="deck.id"
            :enforces-color-identity="deck.enforces_color_identity"
            :max-copies="deck.max_copies"
            :cards="cards"
        />
        <card-add-modal v-if="showAddModal" :deck="deck" :cards="cards" @close="showAddModal = false" />
        <add-companion-modal
            v-if="showCompanionModal"
            :deck-id="deck.id"
            :format="deck.format"
            :roster="companionRoster"
            :banned-as-companion="deck.banned_as_companion"
            :enforces-color-identity="deck.enforces_color_identity"
            :commander-color-identity="commanderColorIdentity"
            @close="showCompanionModal = false"
        />
    </div>
</template>

<style lang="scss" scoped>
.deck-navigation-add {
    display: flex;
    flex-wrap: wrap;

    gap: 1rem;
}
</style>
