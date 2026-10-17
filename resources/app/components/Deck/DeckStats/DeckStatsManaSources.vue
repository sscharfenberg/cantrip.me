<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { karstenArticleUrl } from "@/utils/frankKarstenAnalysis";
import Headline from "Components/UI/Headline.vue";
import InfoBox from "Components/UI/InfoBox.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import type { KarstenColorAnalysis, KarstenCombinedAnalysis } from "Composables/useDeckStats.ts";
import { useFormatting } from "Composables/useFormatting.ts";

const props = defineProps<{
    /** Per-color Karsten source recommendation for the deck's format. */
    karsten: KarstenColorAnalysis[];
    /**
     * Karsten gold-card "combined" rows — one per unique color combo
     * demanded by a gold or hybrid card in the deck. Empty for
     * monocolor decks or decks without gold cards.
     */
    karstenCombined: KarstenCombinedAnalysis[];
    /** Deck format slug — drives which Karsten article the attribution links to. */
    format: string;
}>();

const { t } = useI18n();
/** Source counts are fractional once mana rocks, dorks and ramp count (Signet = ¾), so they go through the locale formatter. */
const { formatDecimals } = useFormatting();

/**
 * Karsten article URL the attribution links to, derived from the deck
 * format (so a Commander deck and a Modern deck can deep-link to
 * different editions of the analysis if they exist).
 */
const karstenUrl = computed(() => karstenArticleUrl(props.format));
</script>

<template>
    <figure class="mana-sources">
        <headline :size="4">{{ t("pages.deck.stats.colors.karsten.title") }}</headline>
        <ul v-if="karsten.length" class="mana-sources__rows">
            <li
                v-for="row in karsten"
                :key="row.color"
                class="mana-sources__row"
                :class="{ 'mana-sources__row--short': row.short > 0 }"
            >
                <i18n-t
                    :keypath="
                        row.short > 0
                            ? 'pages.deck.stats.colors.karsten.short'
                            : 'pages.deck.stats.colors.karsten.sufficient'
                    "
                    scope="global"
                >
                    <template #color>
                        <img
                            :src="`/symbol/${row.color}.svg`"
                            :alt="t(`pages.deck.stats.colors.color.${row.color}`)"
                            class="mana-sources__symbol"
                        />
                    </template>
                    <template #have>{{ formatDecimals(row.have) }}</template>
                    <template #need>{{ row.need }}</template>
                    <template #short>{{ formatDecimals(row.short) }}</template>
                    <template #card>{{ row.card }}</template>
                    <template #mv>{{ row.mv }}</template>
                </i18n-t>
            </li>
        </ul>
        <section v-if="karstenCombined.length" class="mana-sources__combined">
            <h5 class="mana-sources__subheading">
                {{ t("pages.deck.stats.colors.karsten.combined_title") }}
            </h5>
            <ul class="mana-sources__rows">
                <li
                    v-for="row in karstenCombined"
                    :key="row.colors.join('')"
                    class="mana-sources__row"
                    :class="{ 'mana-sources__row--short': row.short > 0 }"
                >
                    <i18n-t
                        :keypath="
                            row.short > 0
                                ? 'pages.deck.stats.colors.karsten.combined_short'
                                : 'pages.deck.stats.colors.karsten.combined_sufficient'
                        "
                        scope="global"
                    >
                        <template #colors>
                            <span class="mana-sources__combined-colors">
                                <img
                                    v-for="c in row.colors"
                                    :key="c"
                                    :src="`/symbol/${c}.svg`"
                                    :alt="t(`pages.deck.stats.colors.color.${c}`)"
                                    class="mana-sources__symbol"
                                />
                            </span>
                        </template>
                        <template #have>{{ formatDecimals(row.have) }}</template>
                        <template #need>{{ row.need }}</template>
                        <template #short>{{ formatDecimals(row.short) }}</template>
                        <template #card>{{ row.card }}</template>
                        <template #mv>{{ row.mv }}</template>
                    </i18n-t>
                </li>
            </ul>
        </section>
        <figcaption>
            <info-box class="mana-sources__explanation">
                <p class="mana-sources__note">{{ t("pages.deck.stats.colors.karsten.weighting") }}</p>
                <p class="mana-sources__note">
                    <i18n-t keypath="pages.deck.stats.colors.karsten.attribution" scope="global" tag="span">
                        <template #link>
                            <labelled-link :href="karstenUrl">{{
                                t("pages.deck.stats.colors.karsten.attribution_link")
                            }}</labelled-link>
                        </template>
                    </i18n-t>
                </p>
            </info-box>
        </figcaption>
    </figure>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/sizes" as s;

// One column at every width: each row is a sentence naming a spell, so
// it reads top to bottom and simply wraps — under its text, thanks to
// the hanging indent — on a narrow panel.
.mana-sources {
    display: flex;
    flex-direction: column;

    margin: 0;
    gap: map.get(s.$components, "mana-sources", "gap");

    &__rows {
        display: flex;
        flex-direction: column;

        padding: 0;
        margin: 0;
        gap: #{map.get(s.$components, "mana-sources", "gap") * 0.5};

        list-style: none;

        font-size: 0.9em;
        font-variant-numeric: tabular-nums;
    }

    &__row {
        padding-left: map.get(s.$components, "mana-sources", "indent");

        text-indent: calc(-1 * #{map.get(s.$components, "mana-sources", "indent")});

        &--short {
            font-weight: 600;
        }
    }

    &__combined {
        display: flex;
        flex-direction: column;

        gap: #{map.get(s.$components, "mana-sources", "gap") * 0.5};
    }

    &__subheading {
        margin: 0;

        font-size: 1rem;
        font-weight: 400;
    }

    &__combined-colors {
        display: inline;

        margin-right: 0.25em;

        // Defeat the per-symbol right margin so multiple symbols sit
        // tight together — only the trailing one needs spacing from the
        // " {have} sources" text.
        & .mana-sources__symbol {
            margin-right: 0;
        }
    }

    &__symbol {
        width: 1em;
        height: 1em;
        margin-right: 0.25em;

        vertical-align: -0.15em;
    }

    // Applies to the InfoBox root, as in DeckStatsColors.
    &__explanation {
        max-width: map.get(s.$components, "mana-sources", "note-width");

        font-size: smaller;
    }
}
</style>
