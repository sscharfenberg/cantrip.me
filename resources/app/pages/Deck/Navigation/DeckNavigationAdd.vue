<script setup lang="ts">
import { ref } from "vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckCardRow, DeckCompanion, DeckMeta } from "Types/deckPage.ts";
import AddCompanionModal from "../Modals/AddCompanionModal.vue";
import CardAddModal from "../Modals/CardAddModal.vue";
import DeckCommandZoneModal from "../Modals/DeckCommandZoneModal.vue";
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
/**
 * Controls visibility of the command zone picker — the way to change the
 * commander from the deck's controls, and the only way to give a deck one
 * again once its commander is gone (the commander's own menu goes with it).
 */
const showCommandZoneModal = ref(false);
</script>

<template>
    <div class="deck-navigation-add">
        <button type="button" class="btn-default" @click="showAddModal = true">
            <icon name="add" />
            {{ $t("pages.deck.add.label") }}
        </button>
        <button
            v-if="deck.requires_commander"
            type="button"
            class="btn-default"
            @click="showCommandZoneModal = true"
        >
            <icon name="register" />
            {{ $t("pages.deck.command_zone.label") }}
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
        <card-add-modal v-if="showAddModal" :deck="deck" @close="showAddModal = false" />
        <deck-command-zone-modal
            v-if="showCommandZoneModal"
            :deck-id="deck.id"
            :format="deck.format"
            @close="showCommandZoneModal = false"
        />
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
