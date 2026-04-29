<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { combineCI } from "@/utils/colorIdentity.ts";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import { useDeckSort } from "Composables/useDeckSort.ts";
import { useDeckView } from "Composables/useDeckView.ts";
import type {
    DeckCardRow,
    DeckCategoryRow,
    DeckCommander,
    DeckCompanion,
    DeckMeta,
    DeckViolation
} from "Types/deckPage.ts";
import CardViewImage from "./Cards/CardViewImage.vue";
import CardViewText from "./Cards/CardViewText.vue";
import DeckHeader from "./DeckHeader.vue";
import DeckNavigation from "./Navigation/DeckNavigation.vue";
const props = defineProps<{
    /**
     * True when the request user owns the deck. Drives the read-only
     * "viewer mode" for non-owners on public decks: action menus, drag
     * handles, the add-card nav and the quick-add sidebar are all hidden.
     */
    isOwner: boolean;
    /** Deck metadata (name, format, state, colors, etc.). */
    deck: DeckMeta;
    /** Commanders / command zone cards with full oracle + printing data. */
    commanders: DeckCommander[];
    /** Currently-set companion card, or null. */
    companion: DeckCompanion | null;
    /** The ten companion roster, shaped for the picker modal. */
    companionRoster: DeckCompanion[];
    /** All cards in the deck with full oracle + printing data. */
    cards: DeckCardRow[];
    /** User-defined categories for this deck. */
    categories: DeckCategoryRow[];
    /** Maximum length for a category name. */
    categoryNameMax: number;
    /** Legality violations computed on the server — drives the legality panel. */
    violations: DeckViolation[];
    /**
     * Effective collection-integration mode for this user/deck. Mode A is
     * also returned to non-owners so the deck view stays silent for them.
     * Mode C is the only mode that surfaces per-card status badges.
     */
    collectionMode: "A" | "B" | "C";
}>();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([{ labelKey: "pages.decks.link", href: "/decks", icon: "deck" }, { label: props.deck.name }]);
/** Effective deck view mode — localStorage override for this deck, or the user's default. */
const { viewMode } = useDeckView(props.deck.id);
/** Effective deck sort mode — localStorage override for this deck, or the user's default. */
const { sortMode } = useDeckSort(props.deck.id);
/** Combined commander color identity — shared by navigation and both card views. */
const commanderColorIdentity = computed(() => combineCI(props.commanders.map(c => c.color_identity)));
</script>

<template>
    <Head
        ><title>{{ $t("pages.deck.title", { name: deck.name }) }}</title></Head
    >
    <deck-header
        :deck="deck"
        :is-owner="isOwner"
        :has-commanders="commanders.length > 0"
        :companion="companion"
        :cards="cards"
        :categories="categories"
        :category-name-max="categoryNameMax"
        :violations="violations"
        :hero-art-crop="deck.hero_card?.art_crop ?? null"
    />
    <deck-navigation
        :deck="deck"
        :is-owner="isOwner"
        :cards="cards"
        :companion="companion"
        :companion-roster="companionRoster"
        :commander-color-identity="commanderColorIdentity"
    />
    <card-view-text
        v-if="viewMode === 'text'"
        :deck="deck"
        :is-owner="isOwner"
        :commanders="commanders"
        :companion="companion"
        :cards="cards"
        :categories="categories"
        :sort-mode="sortMode"
        :category-name-max="categoryNameMax"
        :max-copies="deck.max_copies"
        :is-singleton="deck.is_singleton"
        :collection-mode="collectionMode"
    />
    <card-view-image
        v-if="viewMode === 'cards'"
        :deck="deck"
        :is-owner="isOwner"
        :commanders="commanders"
        :companion="companion"
        :cards="cards"
        :categories="categories"
        :sort-mode="sortMode"
        :category-name-max="categoryNameMax"
        :max-copies="deck.max_copies"
        :is-singleton="deck.is_singleton"
        :collection-mode="collectionMode"
    />

</template>
