<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import CardViewImage from "@/pages/Decks/Deck/Cards/CardViewImage.vue";
import CardViewText from "@/pages/Decks/Deck/Cards/CardViewText.vue";
import DeckHeader from "@/pages/Decks/Deck/DeckHeader.vue";
import DeckNavigation from "@/pages/Decks/Deck/Navigation/DeckNavigation.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import { useDeckSort } from "Composables/useDeckSort.ts";
import { useDeckView } from "Composables/useDeckView.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckMeta, DeckViolation } from "Types/deckPage";
const props = defineProps<{
    /** Deck metadata (name, format, state, colors, etc.). */
    deck: DeckMeta;
    /** Commanders / command zone cards with full oracle + printing data. */
    commanders: DeckCommander[];
    /** All cards in the deck with full oracle + printing data. */
    cards: DeckCardRow[];
    /** User-defined categories for this deck. */
    categories: DeckCategoryRow[];
    /** Maximum length for a category name. */
    categoryNameMax: number;
    /** Legality violations computed on the server — drives the legality panel. */
    violations: DeckViolation[];
}>();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "pages.decks.link", href: "/decks", icon: "deck" }, { label: props.deck.name }]);
/** Effective deck view mode — localStorage override for this deck, or the user's default. */
const { viewMode } = useDeckView(props.deck.id);
/** Effective deck sort mode — localStorage override for this deck, or the user's default. */
const { sortMode } = useDeckSort(props.deck.id);
</script>

<template>
    <Head
        ><title>{{ $t("pages.deck.title", { name: deck.name }) }}</title></Head
    >
    <deck-header
        :deck="deck"
        :has-commanders="commanders.length > 0"
        :cards="cards"
        :categories="categories"
        :category-name-max="categoryNameMax"
        :violations="violations"
    />
    <deck-navigation :deck="deck" :cards="cards" />
    <card-view-text
        v-if="viewMode === 'text'"
        :deck-id="deck.id"
        :commanders="commanders"
        :cards="cards"
        :categories="categories"
        :sort-mode="sortMode"
        :category-name-max="categoryNameMax"
        :max-copies="deck.max_copies"
        :is-singleton="deck.is_singleton"
    />
    <card-view-image
        v-if="viewMode === 'cards'"
        :deck-id="deck.id"
        :commanders="commanders"
        :cards="cards"
        :categories="categories"
        :sort-mode="sortMode"
        :category-name-max="categoryNameMax"
        :max-copies="deck.max_copies"
        :is-singleton="deck.is_singleton"
    />

    <section>
        <template v-if="deck.default_card_image">
            <h3>Default Card Image</h3>
            <img
                v-if="deck.default_card_image.card_image_0"
                :src="deck.default_card_image.card_image_0"
                :alt="deck.name"
            />
            <img
                v-if="deck.default_card_image.card_image_1"
                :src="deck.default_card_image.card_image_1"
                :alt="deck.name"
            />
        </template>
    </section>
</template>
