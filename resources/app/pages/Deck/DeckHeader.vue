<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import ColorIdentity from "Components/Card/ColorIdentity.vue";
import DeckState from "Components/Deck/DeckState.vue";
import Badge from "Components/UI/Badge.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import VisibilityBadge from "Components/UI/VisibilityBadge.vue";
import type { DeckCardRow, DeckCategoryRow, DeckCompanion, DeckMeta, DeckViolation } from "Types/deckPage.ts";
import DeckActionsMenu from "./Actions/DeckActionsMenu.vue";
import DeckLegalityPanel from "./DeckLegalityPanel.vue";
const props = defineProps<{
    /** Deck metadata (name, format, state, colors, etc.). */
    deck: DeckMeta;
    /** True when the request user owns the deck — gates the actions menu. */
    isOwner: boolean;
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
    /** URL of the deck's hero art crop, used as the section background. */
    heroArtCrop: string | null;
}>();
const heroBackgroundStyle = computed<Record<string, string> | undefined>(() =>
    props.heroArtCrop ? { "--hero-art-crop": `url('${props.heroArtCrop}')` } : undefined
);
const { t } = useI18n();
</script>

<template>
    <section class="deck-meta" :class="{ 'deck-meta--has-hero': heroArtCrop }">
        <div v-if="heroArtCrop" class="deck-meta__hero" :style="heroBackgroundStyle" />
        <header class="deck-meta__name">
            {{ deck.name.toUpperCase() }}
            <deck-actions-menu
                v-if="isOwner"
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
@use "sass:map";
@use "Abstracts/colors" as c;

:deep(.badge.badge) {
    padding: 0.2rem 0.5rem;

    background-color: map.get(c.$pages, "deck", "meta", "badge-background");
    color: map.get(c.$pages, "deck", "meta", "badge-surface");
}

:deep(p) {
    margin: 0;

    white-space: pre-wrap;
}

.deck-meta--has-hero {
    position: relative;
    isolation: isolate;

    background-color: map.get(c.$pages, "deck", "meta", "hero-tint");
}

.deck-meta__hero {
    position: absolute;
    inset: 0 0 0 50%;
    z-index: -1;

    background-image: var(--hero-art-crop);
    background-position: center;
    background-size: cover;
    mask-composite: intersect;

    // Soft vignette: horizontal fade on the left blends the artwork
    // into the surrounding tint, plus vertical fade top/bottom so tall
    // sections don't show the artwork as a hard rectangle of pixels.
    // `mask-composite: intersect` ANDs the two masks — the artwork is
    // visible only where both alphas pass.
    mask-image:
        linear-gradient(to right, transparent 0%, black 30%, black 100%),
        linear-gradient(to bottom, transparent 0%, black 15%, black 85%, transparent 100%);
}
</style>
