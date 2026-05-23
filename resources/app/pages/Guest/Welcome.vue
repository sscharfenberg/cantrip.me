<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import WelcomeCollectionStats from "@/pages/Guest/WelcomeCollectionStats.vue";
import WelcomeDecksStats from "@/pages/Guest/WelcomeDecksStats.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import Paragraph from "Components/UI/Paragraph.vue";
import Stats from "Components/UI/Stats/Stats.vue";
import StatsItem from "Components/UI/Stats/StatsItem.vue";
import { useFormatting } from "Composables/useFormatting.ts";
const { formatDecimals, formatBytes } = useFormatting();
const scryfallLogo = new URL("../../assets/images/scryfall.svg", import.meta.url).href;
defineProps<{
    scryfallStats: {
        oracleCards: { num: number; size: number };
        defaultCards: { num: number; size: number };
        sets: number;
        artists: number;
        artCrops: { num: number; size: number };
        cardImages: { num: number; size: number };
    };
    siteStats: {
        totalCards: number;
        uniqueCards: number;
        containers: number;
        totalPrice: number;
        containerTypes: Record<string, number>;
        rarities: Record<"common" | "uncommon" | "rare" | "mythic", number>;
        topSets: Array<{ code: string; name: string; count: number }>;
        mostValuableCard: {
            name: string;
            set_code: string;
            card_image_0: string | null;
            price: number;
        } | null;
        mostOwnedCard: {
            name: string;
            set_code: string;
            card_image_0: string | null;
            owned: number;
        } | null;
    };
    deckStats: {
        totalDecks: number;
        totalWorth: number;
        avgWorth: number;
        medianWorth: number;
        formats: Record<string, number>;
        states: Record<string, number>;
        modes: Record<string, number>;
        colors: Record<"W" | "U" | "B" | "R" | "G", number>;
    };
}>();
</script>

<template>
    <Head
        ><title>{{ $t("pages.welcome.title") }}</title></Head
    >
    <headline>
        <icon name="home" :size="3" />
        {{ $t("pages.welcome.claim") }}
    </headline>
    <paragraph>{{ $t("pages.welcome.intro") }}</paragraph>
    <headline :size="3">{{ $t("pages.welcome.scryfall_stats.title") }}</headline>
    <stats>
        <stats-item v-if="scryfallStats.oracleCards.num > 0">
            <template #title>{{ $t("pages.welcome.scryfall_stats.oracle.title") }}</template>
            <template #icon>
                <img src="/symbol/W.svg" alt="white mana" class="icon medium" />
            </template>
            <template #value>{{ formatDecimals(scryfallStats.oracleCards.num) }}</template>
            <template #detail><icon name="file" />{{ formatBytes(scryfallStats.oracleCards.size) }}</template>
            <template #explanation>{{ $t("pages.welcome.scryfall_stats.oracle.explanation") }}</template>
        </stats-item>
        <stats-item v-if="scryfallStats.defaultCards.num > 0">
            <template #title>{{ $t("pages.welcome.scryfall_stats.default.title") }}</template>
            <template #icon>
                <img src="/symbol/U.svg" alt="blue mana" class="icon medium" />
            </template>
            <template #value>{{ formatDecimals(scryfallStats.defaultCards.num) }}</template>
            <template #detail><icon name="file" />{{ formatBytes(scryfallStats.defaultCards.size) }}</template>
            <template #explanation>{{ $t("pages.welcome.scryfall_stats.default.explanation") }}</template>
        </stats-item>
        <stats-item v-if="scryfallStats.sets > 0">
            <template #title>{{ $t("pages.welcome.scryfall_stats.sets.title") }}</template>
            <template #icon>
                <img src="/symbol/B.svg" alt="black mana" class="icon medium" />
            </template>
            <template #value>{{ formatDecimals(scryfallStats.sets) }}</template>
            <template #explanation>{{ $t("pages.welcome.scryfall_stats.sets.explanation") }}</template>
        </stats-item>
        <stats-item v-if="scryfallStats.artists > 0">
            <template #title>{{ $t("pages.welcome.scryfall_stats.artists.title") }}</template>
            <template #icon>
                <img src="/symbol/R.svg" alt="red mana" class="icon medium" />
            </template>
            <template #value>{{ formatDecimals(scryfallStats.artists) }}</template>
            <template #explanation>{{ $t("pages.welcome.scryfall_stats.artists.explanation") }}</template>
        </stats-item>
        <stats-item v-if="scryfallStats.artCrops.num > 0">
            <template #title>{{ $t("pages.welcome.scryfall_stats.artCrops.title") }}</template>
            <template #icon>
                <img src="/symbol/G.svg" alt="green mana" class="icon medium" />
            </template>
            <template #value>{{ formatDecimals(scryfallStats.artCrops.num) }}</template>
            <template #detail><icon name="file" />{{ formatBytes(scryfallStats.artCrops.size) }}</template>
            <template #explanation>{{ $t("pages.welcome.scryfall_stats.artCrops.explanation") }}</template>
        </stats-item>
        <stats-item v-if="scryfallStats.cardImages.num > 0">
            <template #title>{{ $t("pages.welcome.scryfall_stats.cardImages.title") }}</template>
            <template #icon>
                <img src="/symbol/T.svg" alt="tap symbol" class="icon medium" />
            </template>
            <template #value>{{ formatDecimals(scryfallStats.cardImages.num) }}</template>
            <template #detail><icon name="file" />{{ formatBytes(scryfallStats.cardImages.size) }}</template>
            <template #explanation>{{ $t("pages.welcome.scryfall_stats.cardImages.explanation") }}</template>
        </stats-item>
        <stats-item>
            <template #title>{{ $t("pages.welcome.scryfall_stats.scryfall.title") }}</template>
            <template #icon>
                <img :src="scryfallLogo" alt="Scryfall" class="icon medium" />
            </template>
            <template #detail>
                <a href="https://scryfall.com" target="_blank" rel="noopener" class="btn-primary"
                    ><icon name="external-link" />scryfall</a
                >
            </template>
            <template #explanation>{{ $t("pages.welcome.scryfall_stats.scryfall.explanation") }}</template>
        </stats-item>
    </stats>
    <template v-if="siteStats.totalCards > 0 || siteStats.containers > 0 || siteStats.totalPrice > 0">
        <br />
        <headline :size="3">{{ $t("pages.welcome.site_stats.title") }}</headline>
        <welcome-collection-stats :stats="siteStats" />
    </template>
    <template v-if="deckStats.totalDecks > 0">
        <br />
        <headline :size="3">{{ $t("pages.welcome.decks_stats.title") }}</headline>
        <welcome-decks-stats :stats="deckStats" />
    </template>
</template>
