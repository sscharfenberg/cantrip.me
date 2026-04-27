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
    <section class="deck-meta" :class="{ 'deck-meta--has-hero': heroArtCrop }" :style="heroBackgroundStyle">
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

// The art crop is sized to 50% of the section width with `auto` height so
// its natural aspect ratio is preserved. On wide-but-short sections this
// makes the image taller than the section (vertical overflow is clipped to
// the section bounds, showing the middle band of the artwork — the implicit
// zoom). When the section grows tall (e.g. legality panel expanded) more of
// the image becomes visible vertically without any distortion.
// Why the gradient stops: the image's left edge sits at section x=50%, but
// the tint is still fully opaque there — so the seam is hidden. The fade
// only starts at x=50%, so the artwork emerges
// smoothly across the right half with no harsh edge.
.deck-meta--has-hero {
    background-image:
        linear-gradient(
            to right,
            map.get(c.$pages, "deck", "meta", "hero-tint") 0%,
            map.get(c.$pages, "deck", "meta", "hero-tint") 50%,
            transparent 100%
        ),
        var(--hero-art-crop);
    background-repeat: no-repeat;
    background-position:
        center,
        right center;
    background-size:
        100% 100%,
        50% auto;
}
</style>
