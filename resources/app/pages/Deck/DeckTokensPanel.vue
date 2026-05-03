<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import CardFaceImage from "Components/Card/CardFaceImage.vue";
import Accordion from "Components/UI/Accordion.vue";
import Icon from "Components/UI/Icon.vue";
import type { DeckToken } from "Types/deckPage.ts";
const props = defineProps<{
    /** Tokens created by cards in this deck — printing-correct, deduped server-side. */
    tokens: DeckToken[];
    /**
     * Lookup of `default_card_id → card name` for everything in the deck
     * (commanders + companion + the 99). Used to resolve each token's
     * `source_default_card_ids` into card names for the "Needed for: …"
     * tooltip.
     */
    cardNameByDefaultCardId: Record<string, string>;
}>();
const { t } = useI18n();
/**
 * Build a sort key from a token's `color_identity` so WUBRG order is
 * preserved without a chained comparator: each color letter maps to its
 * position in `WUBRG`, prefixed with the color count so monocolor sorts
 * before multicolor and colorless sorts last.
 *
 *   null/""  → "9"           (colorless: pushed to the end)
 *   "W"      → "1-0"
 *   "G"      → "1-4"
 *   "WU"     → "2-0-1"
 *   "WUBRG"  → "5-0-1-2-3-4"
 *
 * Lexical comparison on these keys yields the canonical Magic ordering.
 */
const COLOR_ORDER = "WUBRG";
const colorRank = (ci: string | null | undefined): string => {
    if (!ci) return "9";

    return [ci.length, ...ci.split("").map(c => COLOR_ORDER.indexOf(c))].join("-");
};
/** Tokens sorted by color (WUBRG, multicolor by length, colorless last), then alphabetically by name. */
const sortedTokens = computed(() =>
    [...props.tokens].sort((a, b) => {
        const colorCompare = colorRank(a.color_identity).localeCompare(colorRank(b.color_identity));
        return colorCompare !== 0 ? colorCompare : a.name.localeCompare(b.name);
    })
);
/**
 * Build the "Needed for: …" tooltip for a token. Resolves each
 * `source_default_card_id` to the matching deck card's name via the
 * lookup, dedupes (a card with multiple printings would otherwise
 * appear once per printing), sorts alphabetically, joins with `<br>`.
 */
const tooltipFor = (token: DeckToken): string => {
    const names = Array.from(
        new Set(token.source_default_card_ids.map(id => props.cardNameByDefaultCardId[id]).filter(Boolean))
    ).sort();
    return [t("pages.deck.tokens.needed_for"), ...names].join("<br />");
};
</script>

<template>
    <accordion>
        <template #head>
            <span class="tokens-head">
                <icon name="card" />
                <span>
                    <i18n-t keypath="pages.deck.tokens.title" scope="global" :plural="sortedTokens.length">
                        <template #count
                            ><strong>{{ sortedTokens.length }}</strong></template
                        >
                    </i18n-t>
                </span>
            </span>
        </template>
        <template #body>
            <ul v-if="sortedTokens.length">
                <li
                    v-for="token in sortedTokens"
                    :key="token.id"
                    v-tooltip="{ content: tooltipFor(token), html: true }"
                >
                    <card-face-image :card="token" />
                </li>
            </ul>
        </template>
    </accordion>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

ul {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(8rem, 100%), 1fr));

    padding: 0;
    margin: 0;
    gap: map.get(s.$components, "tokens", "gap");

    list-style: none;

    @include m.mq("portrait") {
        grid-template-columns: repeat(auto-fit, minmax(min(10rem, 100%), 1fr));
    }
}

.tokens-head {
    display: flex;
    align-items: center;

    gap: map.get(s.$components, "tokens", "gap");
}

.face-image {
    border-radius: map.get(s.$components, "tokens", "card-radius");

    @include m.mq("portrait") {
        border-radius: map.get(s.$components, "tokens", "card-radius-portrait");
    }
}
</style>
