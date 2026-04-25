<script setup lang="ts">
import { useI18n } from "vue-i18n";
import DeckActionsMenu from "@/pages/Decks/Deck/Actions/DeckActionsMenu.vue";
import DeckLegalityPanel from "@/pages/Decks/Deck/DeckLegalityPanel.vue";
import ColorIdentity from "Components/Card/ColorIdentity.vue";
import DeckState from "Components/Deck/DeckState.vue";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import VisibilityBadge from "Components/UI/VisibilityBadge.vue";
import type { DeckCardRow, DeckCategoryRow, DeckCompanion, DeckMeta, DeckViolation } from "Types/deckPage.ts";
defineProps<{
    /** Deck metadata (name, format, state, colors, etc.). */
    deck: DeckMeta;
    /** hasCommanders **/
    hasCommanders: boolean;
    /** Currently-set companion card, or null — forwarded to the actions menu. */
    companion: DeckCompanion | null;
    /** All cards in the deck. */
    cards: DeckCardRow[];
    /** User-defined categories for this deck. */
    categories: DeckCategoryRow[];
    /** Maximum length for a category name. */
    categoryNameMax: number;
    /** Legality violations for this deck — the panel only renders when non-empty. */
    violations: DeckViolation[];
}>();
const { t } = useI18n();
</script>

<template>
    <section class="deck-meta">
        <header class="deck-meta__name">
            {{ deck.name.toUpperCase() }}
            <deck-actions-menu
                :deck="deck"
                :companion="companion"
                :cards="cards"
                :categories="categories"
                :category-name-max="categoryNameMax"
            />
        </header>
        <div class="deck-meta__badges">
            <badge v-if="deck.colors"><color-identity :color-identity="deck.colors" /></badge>
            <badge type="info" v-tooltip="`${t('pages.deck.format')}: ${t('enums.card_formats.' + deck.format)}`"
                ><icon name="spell" :size="1" />{{ $t(`enums.card_formats.${deck.format}`) }}</badge
            >
            <badge
                v-if="hasCommanders && deck.bracket"
                v-tooltip="`${t('pages.deck.bracket')} ${deck.bracket}: ${t('enums.bracket.' + deck.bracket)}`"
            >
                <icon name="swords" :size="1" />{{ deck.bracket }}
            </badge>
            <deck-state :state="deck.state" />
            <badge type="info"><icon name="deck" :size="1" />{{ deck.card_count }}</badge>
            <visibility-badge :visibility="deck.visibility" />
        </div>
        <paragraph v-if="deck.description">{{ deck.description }}</paragraph>
        <deck-legality-panel v-if="violations.length > 0" :violations="violations" :cards="cards" />
    </section>
</template>

<style lang="scss" scoped>
:deep(.badge) {
    padding: 0.2rem 0.5rem;
}

:deep(p) {
    margin: 0;
}
</style>
