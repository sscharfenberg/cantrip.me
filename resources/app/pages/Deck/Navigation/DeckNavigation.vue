<script setup lang="ts">
import type { DeckCardRow, DeckCompanion, DeckMeta } from "Types/deckPage.ts";
import DeckNavigationAdd from "./DeckNavigationAdd.vue";
import DeckNavigationSort from "./DeckNavigationSort.vue";
import DeckNavigationView from "./DeckNavigationView.vue";
defineProps<{
    /** Deck metadata (name, format, state, colors, etc.). */
    deck: DeckMeta;
    /**
     * True when the request user owns the deck. Hides the add-card / add-companion
     * / quick-add controls for non-owner viewers; sort and view controls are
     * personal preferences and stay available for everyone.
     */
    isOwner: boolean;
    /** All cards in the deck — forwarded for zone capacity checks. */
    cards: DeckCardRow[];
    /** Currently-set companion card, or null. */
    companion: DeckCompanion | null;
    /** The ten companion roster, shaped for the picker modal. */
    companionRoster: DeckCompanion[];
    /** Combined commander color identity, pre-unioned from the commanders list. */
    commanderColorIdentity: string;
}>();
</script>

<template>
    <div :aria-label="$t('pages.deck.navigation.label')" class="deck-navigation">
        <deck-navigation-add
            v-if="isOwner"
            :deck="deck"
            :cards="cards"
            :companion="companion"
            :companion-roster="companionRoster"
            :commander-color-identity="commanderColorIdentity"
        />
        <deck-navigation-view :deck="deck" />
        <deck-navigation-sort :deck="deck" />
    </div>
</template>

<style lang="scss" scoped>
.deck-navigation {
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;

    margin: 0 0 1rem;
    gap: 1rem;
}
</style>
