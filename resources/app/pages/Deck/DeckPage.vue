<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { combineCI } from "@/utils/colorIdentity.ts";
import DeckStatsSection from "Components/Deck/DeckStats/DeckStatsSection.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs.ts";
import { provideDeckHighlight } from "Composables/useDeckHighlight.ts";
import { useDeckSort } from "Composables/useDeckSort.ts";
import { useDeckView } from "Composables/useDeckView.ts";
import type {
    DeckCardRow,
    DeckCategoryRow,
    DeckCommander,
    DeckCompanion,
    DeckMeta,
    DeckToken,
    DeckViolation
} from "Types/deckPage.ts";
import CardViewImage from "./Cards/CardViewImage.vue";
import CardViewText from "./Cards/CardViewText.vue";
import DeckHeader from "./DeckHeader.vue";
import DeckTokensPanel from "./DeckTokensPanel.vue";
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
    /**
     * Badge-presentation mode — equals `collectionMode` except when the
     * deck is in mode B with no `container_id`, where it demotes to A so
     * the header badge doesn't claim "Implicit tracking" while no per-row
     * badges actually render. The real `collectionMode` still drives the
     * wizard trigger and the modal's why-recap + actions.
     */
    collectionBadgeMode: "A" | "B" | "C";
    /**
     * Owner-only context shaping the collection-mode modal in `DeckHeader`.
     * Null for non-owners — the badge that opens the modal is gated on
     * `isOwner`, so a missing context is never reached on the modal side.
     */
    collectionModeContext: {
        master_switch_enabled: boolean;
        has_stacks: boolean;
        has_container: boolean;
        claimed_count: number;
    } | null;
    /**
     * Owner's containers — drives the "Add all cards to collection" modal
     * inside `DeckActionsMenu`. Empty array for non-owners.
     */
    containers: { id: string; name: string; type: string; is_deckbox: boolean }[];
    /**
     * Tokens (and other `all_parts` printing edges) created by cards inDeckSta
     * this deck — already deduped on the related printing id and
     * sorted alphabetically server-side.
     */
    tokens: DeckToken[];
}>();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "pages.decks.link", href: "/decks", icon: "deck" },
    ...(props.deck.state === "archived"
        ? [{ labelKey: "pages.decks.archived_link", href: "/decks/archived", icon: "archived" }]
        : []),
    { label: props.deck.name }
]);
/** Effective deck view mode — localStorage override for this deck, or the user's default. */
const { viewMode } = useDeckView(props.deck.id);
/** Effective deck sort mode — localStorage override for this deck, or the user's default. */
const { sortMode } = useDeckSort(props.deck.id);
/** Combined commander color identity — shared by navigation and both card views. */
const commanderColorIdentity = computed(() => combineCI(props.commanders.map(c => c.color_identity)));
/**
 * True when the deck is archived. Archived decks are read-only by design:
 * card-edit affordances, the add-card nav and the bulk collection actions
 * collapse to a non-owner view. The deck actions menu still surfaces a
 * limited set of entries (restore, delete, QR, CSV) — see DeckActionsMenu.
 */
const isArchived = computed(() => props.deck.state === "archived");
/**
 * Effective owner-edit flag — false for non-owners *and* for archived
 * decks. Components that gate every editing affordance on `isOwner`
 * (DeckNavigation, both card views) receive this; DeckHeader still gets
 * the raw `isOwner` plus `isArchived` so its actions menu can render the
 * archive-specific entries.
 */
const canEdit = computed(() => props.isOwner && !isArchived.value);
/**
 * Provide the deck highlight api once at the page root. Stats panels
 * (manacurve, categories, color distribution) and card views (text /
 * image) inject it via `useDeckHighlight()`. Each axis (mana value,
 * category, color production, color consumption) is independent; a
 * card highlights only when it satisfies every active axis.
 *
 * The cards getter feeds the production-axis matcher's fetchland
 * resolver — without it, fetchlands wouldn't highlight when the user
 * clicks an inner-ring (production) segment because Scryfall reports
 * `produced_mana = null` for them.
 */
const { hasHighlight, clear: clearHighlight } = provideDeckHighlight(() => props.cards);
/**
 * Lookup `default_card_id → card name` covering everything in the deck
 * (commanders + companion + the 99). Powers the `DeckTokensPanel`
 * tooltip without requiring the panel to know about the deck shape.
 */
const cardNameByDefaultCardId = computed<Record<string, string>>(() => {
    const map: Record<string, string> = {};
    for (const card of props.cards) {
        if (card.default_card.id) map[card.default_card.id] = card.name;
    }
    for (const cmd of props.commanders) {
        if (cmd.default_card.id) map[cmd.default_card.id] = cmd.name;
    }
    if (props.companion?.default_card.id) {
        map[props.companion.default_card.id] = props.companion.name;
    }
    return map;
});
</script>

<template>
    <Head
        ><title>{{ $t("pages.deck.title", { name: deck.name }) }}</title></Head
    >
    <deck-header
        :deck="deck"
        :is-owner="isOwner"
        :is-archived="isArchived"
        :has-commanders="commanders.length > 0"
        :companion="companion"
        :cards="cards"
        :categories="categories"
        :category-name-max="categoryNameMax"
        :violations="violations"
        :hero-art-crop="deck.hero_card?.art_crop ?? null"
        :collection-mode="collectionMode"
        :collection-badge-mode="collectionBadgeMode"
        :collection-mode-context="collectionModeContext"
        :containers="containers"
    />
    <deck-navigation
        :deck="deck"
        :is-owner="canEdit"
        :cards="cards"
        :companion="companion"
        :companion-roster="companionRoster"
        :commander-color-identity="commanderColorIdentity"
    />
    <div v-if="hasHighlight" class="selections">
        <button type="button" class="btn-default" @click="clearHighlight">
            <icon name="close" />
            {{ $t("pages.deck.clear_selection") }}
        </button>
    </div>
    <card-view-text
        v-if="viewMode === 'text'"
        :deck="deck"
        :is-owner="canEdit"
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
        :is-owner="canEdit"
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
    <div v-if="tokens.length || cards.length || commanders.length || companion || categories.length" class="deck-stats">
        <deck-tokens-panel
            v-if="tokens.length"
            :tokens="tokens"
            :card-name-by-default-card-id="cardNameByDefaultCardId"
        />
        <deck-stats-section
            v-if="cards.length || commanders.length || companion || categories.length"
            :cards="cards"
            :commanders="commanders"
            :companion="companion"
            :categories="categories"
            :format="deck.format"
        />
    </div>
</template>

<style lang="scss" scoped>
.deck-stats {
    display: flex;
    flex-direction: column;

    margin: 1lh 0;
    gap: 1lh;
}

.selections {
    margin: 0 0 1lh;
}
</style>
