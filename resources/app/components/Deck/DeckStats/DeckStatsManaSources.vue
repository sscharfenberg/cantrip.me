<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { karstenArticleUrl } from "@/utils/frankKarstenAnalysis";
import DeckStatsSpellLink from "Components/Deck/DeckStats/DeckStatsSpellLink.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import InfoBox from "Components/UI/InfoBox.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import type { HighlightColor } from "Composables/useDeckHighlight.ts";
import type {
    AutomaticSpell,
    KarstenColorAnalysis,
    KarstenCombinedAnalysis,
    KarstenCommandZoneCheck
} from "Composables/useDeckStats.ts";
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
    /** Per command-zone card, whether it can be cast on curve. Empty for a deck without commanders. */
    commandZone: KarstenCommandZoneCheck[];
    /** Deck format slug — drives which Karsten article the attribution links to. */
    format: string;
    /** Which spell the rows without a pick show. */
    automaticSpell: AutomaticSpell;
}>();

/**
 * @emits pick — Open the spell picker for the row with this key.
 * @emits automatic-spell — Change which spell the rows without a pick show.
 * @emits reset-picks — Return every row to its automatic spell.
 */
const emit = defineEmits<{
    pick: [key: string];
    "automatic-spell": [choice: AutomaticSpell];
    "reset-picks": [];
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

/** A row's color names for the spell button's accessible label — "White", or "White / Blue". */
const colorLabel = (colors: readonly HighlightColor[]): string =>
    colors.map(c => t(`pages.deck.stats.colors.color.${c}`)).join(" / ");

/** The automatic-spell choices, hardest first — the default, and the question Karsten answers. */
const automaticOptions = computed(() =>
    (["hardest", "easiest", "cheapest"] as const).map(choice => ({
        value: choice,
        label: t(`pages.deck.stats.colors.karsten.automatic.${choice}`)
    }))
);

/**
 * What a command-zone card's line says: that it needs no colored mana,
 * that the deck can cast it on curve, or that it cannot.
 */
const verdict = (check: KarstenCommandZoneCheck): string => {
    const spell = t("pages.deck.stats.colors.karsten.spell", { card: check.card, mv: check.mv });
    if (check.requirements.length === 0) {
        return t("pages.deck.stats.colors.karsten.command_zone.colorless", { spell });
    }
    return check.onCurve
        ? t("pages.deck.stats.colors.karsten.command_zone.on_curve", { spell })
        : t("pages.deck.stats.colors.karsten.command_zone.short", { spell });
};

/** Whether a command-zone card is multicolored — only then does it have more than one requirement to explain. */
const hasMulticolorCommand = computed(() => props.commandZone.some(check => check.requirements.length > 1));

/** Whether any row shows a picked spell — only then is there anything to reset. */
const hasPicks = computed(() => [...props.karsten, ...props.karstenCombined].some(row => row.picked));
</script>

<template>
    <div class="mana-sources">
        <headline :size="4">{{ t("pages.deck.stats.colors.karsten.title") }}</headline>
        <figure class="mana-sources__figure">
            <section v-if="commandZone.length" class="mana-sources__group mana-sources__group--command-zone">
                <h5 class="mana-sources__subheading">
                    {{ t("pages.deck.stats.colors.karsten.command_zone.title") }}
                </h5>
                <ul class="mana-sources__rows">
                    <li v-for="check in commandZone" :key="check.oracleId" class="mana-sources__row">
                        <span
                            class="mana-sources__verdict"
                            :class="{ 'mana-sources__verdict--short': !check.onCurve }"
                            >{{ verdict(check) }}</span
                        >
                        <ul v-if="check.requirements.length" class="mana-sources__requirements">
                            <li
                                v-for="requirement in check.requirements"
                                :key="requirement.key"
                                class="mana-sources__requirement"
                                :data-key="requirement.key"
                                :class="{ 'mana-sources__requirement--short': requirement.short > 0 }"
                            >
                                <i18n-t
                                    keypath="pages.deck.stats.colors.karsten.command_zone.requirement"
                                    scope="global"
                                >
                                    <template #colors>
                                        <span class="mana-sources__combined-colors">
                                            <img
                                                v-for="c in requirement.colors"
                                                :key="c"
                                                :src="`/symbol/${c}.svg`"
                                                :alt="t(`pages.deck.stats.colors.color.${c}`)"
                                                class="mana-sources__symbol"
                                            />
                                        </span>
                                    </template>
                                    <template #have>{{ formatDecimals(requirement.have) }}</template>
                                    <template #need>{{ requirement.need }}</template>
                                </i18n-t>
                            </li>
                        </ul>
                    </li>
                </ul>
                <info-box v-if="hasMulticolorCommand" class="mana-sources__hint">
                    <p>{{ t("pages.deck.stats.colors.karsten.command_zone.multicolor") }}</p>
                </info-box>
            </section>
            <section class="mana-sources__group mana-sources__automatic">
                <h5 class="mana-sources__subheading">{{ t("pages.deck.stats.colors.karsten.automatic.label") }}</h5>
                <mono-select
                    :options="automaticOptions"
                    :selected="automaticSpell"
                    :placeholder="t('pages.deck.stats.colors.karsten.automatic.label')"
                    :sort="false"
                    :clearable="false"
                    @change="(choice: string) => emit('automatic-spell', choice as AutomaticSpell)"
                />
                <info-box class="mana-sources__hint">
                    <p>{{ t("pages.deck.stats.colors.karsten.automatic.hint") }}</p>
                    <p>{{ t("pages.deck.stats.colors.karsten.automatic.hint_one_drops") }}</p>
                </info-box>
                <button v-if="hasPicks" type="button" class="mana-sources__reset" @click="emit('reset-picks')">
                    <icon name="clear" :size="0" />{{ t("pages.deck.stats.colors.karsten.reset_picks") }}
                </button>
            </section>
            <section v-if="karsten.length" class="mana-sources__group mana-sources__group--single">
                <h5 class="mana-sources__subheading">
                    {{ t("pages.deck.stats.colors.karsten.single_title") }}
                </h5>
                <ul class="mana-sources__rows">
                    <li
                        v-for="row in karsten"
                        :key="row.key"
                        class="mana-sources__row"
                        :data-key="row.key"
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
                            <template #spell>
                                <deck-stats-spell-link
                                    :card="row.card"
                                    :mv="row.mv"
                                    :picked="row.picked"
                                    :color-label="colorLabel([row.color])"
                                    @pick="emit('pick', row.key)"
                                />
                            </template>
                        </i18n-t>
                    </li>
                </ul>
            </section>
            <section v-if="karstenCombined.length" class="mana-sources__group mana-sources__group--combined">
                <h5 class="mana-sources__subheading">
                    {{ t("pages.deck.stats.colors.karsten.combined_title") }}
                </h5>
                <ul class="mana-sources__rows">
                    <li
                        v-for="row in karstenCombined"
                        :key="row.key"
                        class="mana-sources__row"
                        :data-key="row.key"
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
                            <template #spell>
                                <deck-stats-spell-link
                                    :card="row.card"
                                    :mv="row.mv"
                                    :picked="row.picked"
                                    :color-label="colorLabel(row.colors)"
                                    @pick="emit('pick', row.key)"
                                />
                            </template>
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
    </div>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

// One column at every width: each row is a sentence naming a spell, so
// it reads top to bottom and simply wraps — under its text, thanks to
// the hanging indent — on a narrow panel.
.mana-sources {
    // The headline sits outside the figure and brings its own bottom
    // margin, so the gap only spaces the figure's own parts.
    &__figure {
        display: flex;
        flex-direction: column;

        margin: 0;
        gap: map.get(s.$components, "mana-sources", "section-gap");
    }

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

        line-height: 1.5;

        text-indent: calc(-1 * #{map.get(s.$components, "mana-sources", "indent")});

        &--short {
            font-weight: 600;
        }
    }

    &__group {
        display: flex;
        flex-direction: column;

        gap: #{map.get(s.$components, "mana-sources", "gap") * 0.5};
    }

    &__subheading {
        margin: 0;

        font-size: 1rem;
        font-weight: 400;
    }

    // A command-zone card's verdict, bold while it falls short — as a
    // short row is, but without its requirements, which say so each.
    &__verdict--short {
        font-weight: 600;
    }

    // The requirements a command-zone card makes, side by side under its
    // verdict. The row's hanging indent is inherited; the list resets it.
    &__requirements {
        display: flex;
        flex-wrap: wrap;

        padding: 0;
        margin: 0;
        gap: 0 1.5ch;

        list-style: none;

        text-indent: 0;
    }

    &__requirement--short {
        font-weight: 600;
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

    // Why a row needs a spell at all, under the choice of which one —
    // the InfoBox root, sized like the explanation at the bottom.
    &__hint {
        max-width: map.get(s.$components, "mana-sources", "note-width");

        font-size: smaller;
    }

    // A button that reads as a text link: it changes state, it goes nowhere.
    &__reset {
        display: inline-flex;
        align-items: center;

        align-self: flex-start;

        padding: 0;
        border: 0;
        gap: 0.5ch;

        background: none;
        color: map.get(c.$components, "textlink", "surface");

        font: inherit;
        font-size: smaller;
        text-decoration: underline;
        text-decoration-color: map.get(c.$components, "textlink", "decoration");
        text-decoration-thickness: map.get(s.$components, "textlink", "underline-thickness");
        text-underline-offset: map.get(s.$components, "textlink", "underline-offset");

        cursor: pointer;

        transition:
            color map.get(ti.$timings, "fast") linear,
            text-decoration-color map.get(ti.$timings, "fast") linear;

        &:hover,
        &:focus-visible {
            color: map.get(c.$components, "textlink", "surface-hover");

            text-decoration-color: map.get(c.$components, "textlink", "decoration-hover");
        }
    }
}
</style>
