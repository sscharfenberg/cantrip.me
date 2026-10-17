<script setup lang="ts">
/******************************************************************************
 * Pick the spell a "Mana sources" row counts sources for.
 *
 * Lists the deck the way the text view does — command zone, companion,
 * default and custom groups, sideboard — narrowed to the cards the row can
 * be priced for, with empty groups left out. Rows are read-only: quantity,
 * name and mana cost, no drag handle and no actions menu. Clicking one picks
 * it; the "Automatic" entry above the groups hands the row back to the
 * main-deck spell it is closest to failing.
 *
 * Grouping comes from `useDeckSections` and the column split from
 * `useResponsiveColumns`, both shared with the text view; the row styling
 * lives in the global deck partials (`_text-group.scss`, `_card.scss`), so
 * all this component adds is the reset that turns a row into a button.
 *****************************************************************************/
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import DeckGroupHeadline from "@/pages/Deck/Sections/DeckGroupHeadline.vue";
import ManaCost from "Components/Card/ManaCost.vue";
import Modal from "Components/Modal/Modal.vue";
import InfoBox from "Components/UI/InfoBox.vue";
import type { HighlightColor } from "Composables/useDeckHighlight.ts";
import { useDeckSections } from "Composables/useDeckSections.ts";
import type { DeckSort } from "Composables/useDeckSort.ts";
import type { AutomaticSpell } from "Composables/useDeckStats.ts";
import { useResponsiveColumns } from "Composables/useResponsiveColumns.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion } from "Types/deckPage.ts";

const props = defineProps<{
    /** The row's color — or colors, for a multicolor row. */
    colors: HighlightColor[];
    /** Oracle ids of the cards the row can be priced for; everything else is left out. */
    candidates: string[];
    /** Oracle id of the picked spell, or null while the row is automatic. */
    picked: string | null;
    /** The spell the row shows while automatic. */
    automatic: { card: string; mv: number };
    /** How that spell is chosen — names it as the hardest or the easiest one. */
    automaticSpell: AutomaticSpell;
    cards: DeckCardRow[];
    commanders: DeckCommander[];
    /** The companion, or null — also null when the format allows none, as in the text view. */
    companion: DeckCompanion | null;
    categories: DeckCategoryRow[];
    /** The deck's active sort mode, so the groups read as they do on the page. */
    sortMode: DeckSort;
    /** Whether the format has a sideboard at all. */
    allowsSideboard: boolean;
}>();

/**
 * @emits select — The picked card's oracle id, or null for "automatic".
 * @emits close — Fired when the modal should be dismissed.
 */
const emit = defineEmits<{ select: [oracleId: string | null]; close: [] }>();

const { t } = useI18n();

const candidateIds = computed(() => new Set(props.candidates));
const isCandidate = (oracleId: string): boolean => candidateIds.value.has(oracleId);

const { sections } = useDeckSections(
    () => props.cards.filter(card => isCandidate(card.oracle_card_id)),
    () => props.commanders.filter(cmd => isCandidate(cmd.oracle_card_id)),
    () => (props.companion !== null && isCandidate(props.companion.oracle_card_id) ? props.companion : null),
    () => props.categories,
    () => props.sortMode,
    () => props.allowsSideboard,
    t,
    // Nothing is dragged here, so no drop-target placeholders either.
    ref(null)
);

/** Same column split as the text view, capped at the two that fit the modal. */
const { containerRef, columns } = useResponsiveColumns(sections, {
    minColWidth: 260,
    maxColumns: 2,
    colGap: 16,
    weight: section => {
        if (section.kind === "commanders") return 1 + section.commanders.length;
        if (section.kind === "companion") return 2;
        return 1 + section.group.cards.length;
    }
});

const isPicked = (oracleId: string): boolean => props.picked === oracleId;
</script>

<template>
    <modal @close="emit('close')">
        <template #header>
            <i18n-t keypath="pages.deck.stats.colors.karsten.picker.title" scope="global">
                <template #colors>
                    <span class="spell-picker__colors">
                        <img
                            v-for="c in colors"
                            :key="c"
                            :src="`/symbol/${c}.svg`"
                            :alt="t(`pages.deck.stats.colors.color.${c}`)"
                            class="spell-picker__symbol"
                        />
                    </span>
                </template>
            </i18n-t>
        </template>
        <info-box class="spell-picker__intro">
            <p>
                {{
                    colors.length > 1
                        ? t("pages.deck.stats.colors.karsten.picker.intro_combined")
                        : t("pages.deck.stats.colors.karsten.picker.intro")
                }}
                <template v-if="allowsSideboard">
                    {{ t("pages.deck.stats.colors.karsten.picker.intro_sideboard") }}
                </template>
            </p>
        </info-box>
        <div class="text-card-groups spell-picker__automatic">
            <section class="text-card-groups__column text-card-group">
                <deck-group-headline>{{ t("pages.deck.stats.colors.karsten.picker.automatic") }}</deck-group-headline>
                <ul class="text-card-group__list">
                    <li>
                        <button
                            type="button"
                            class="card spell-picker__card spell-picker__card--automatic"
                            :class="{ highlighted: picked === null }"
                            :aria-pressed="picked === null"
                            @click="emit('select', null)"
                        >
                            <span class="card__name">{{ t("pages.deck.stats.colors.karsten.spell", automatic) }}</span>
                            <span class="spell-picker__hint">{{
                                t(`pages.deck.stats.colors.karsten.picker.automatic_hint_${automaticSpell}`)
                            }}</span>
                        </button>
                    </li>
                </ul>
            </section>
        </div>
        <div ref="containerRef" class="text-card-groups spell-picker__groups">
            <div v-for="(col, ci) in columns" :key="ci" class="text-card-groups__column">
                <template
                    v-for="section in col"
                    :key="
                        section.kind === 'commanders' ? 'cmd' : section.kind === 'companion' ? 'cmp' : section.group.key
                    "
                >
                    <section v-if="section.kind === 'commanders'" class="text-card-group">
                        <deck-group-headline
                            >{{ t("pages.deck.commanders") }} ({{ section.commanders.length }})</deck-group-headline
                        >
                        <ul class="text-card-group__list">
                            <li v-for="commander in section.commanders" :key="commander.oracle_card_id">
                                <button
                                    type="button"
                                    class="card spell-picker__card"
                                    :class="{ highlighted: isPicked(commander.oracle_card_id) }"
                                    :aria-pressed="isPicked(commander.oracle_card_id)"
                                    @click="emit('select', commander.oracle_card_id)"
                                >
                                    <span class="card__name"
                                        ><span class="card__qty">1x </span>{{ commander.name }}</span
                                    >
                                    <mana-cost :mana-cost="commander.mana_cost" />
                                </button>
                            </li>
                        </ul>
                    </section>
                    <section v-else-if="section.kind === 'companion'" class="text-card-group">
                        <deck-group-headline>{{ t("pages.deck.companion.heading") }}</deck-group-headline>
                        <ul class="text-card-group__list">
                            <li>
                                <button
                                    type="button"
                                    class="card spell-picker__card"
                                    :class="{ highlighted: isPicked(section.companion.oracle_card_id) }"
                                    :aria-pressed="isPicked(section.companion.oracle_card_id)"
                                    @click="emit('select', section.companion.oracle_card_id)"
                                >
                                    <span class="card__name"
                                        ><span class="card__qty">1x </span>{{ section.companion.name }}</span
                                    >
                                    <mana-cost :mana-cost="section.companion.mana_cost" />
                                </button>
                            </li>
                        </ul>
                    </section>
                    <section v-else class="text-card-group">
                        <deck-group-headline>{{ section.group.label }} ({{ section.group.count }})</deck-group-headline>
                        <ul class="text-card-group__list">
                            <li v-for="card in section.group.cards" :key="card.id">
                                <button
                                    type="button"
                                    class="card spell-picker__card"
                                    :class="{ highlighted: isPicked(card.oracle_card_id) }"
                                    :aria-pressed="isPicked(card.oracle_card_id)"
                                    @click="emit('select', card.oracle_card_id)"
                                >
                                    <span class="card__name"
                                        ><span class="card__qty">{{ card.quantity }}x </span>{{ card.name }}</span
                                    >
                                    <mana-cost :mana-cost="card.mana_cost" />
                                </button>
                            </li>
                        </ul>
                    </section>
                </template>
            </div>
        </div>
    </modal>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.spell-picker {
    &__colors {
        display: inline-flex;

        margin: 0 0.25em;
        gap: 0.1em;

        vertical-align: -0.15em;
    }

    &__symbol {
        width: 1em;
        height: 1em;
    }

    &__intro {
        margin: 0 0 1lh;

        font-size: smaller;
    }

    &__automatic {
        margin: 0 0 1lh;
    }

    // A deck-card row (`.text-card-group .card`, from the global partial)
    // that is a button: full width, the row's own font and alignment, and
    // a hover state, since the whole row is the click target.
    &__card {
        width: 100%;

        font: inherit;
        text-align: left;

        cursor: pointer;

        transition: border-color map.get(ti.$timings, "fast") linear;

        &:hover,
        &:focus-visible {
            border-color: map.get(c.$pages, "deck", "card", "selected-border");
        }

        // The automatic entry names a spell rather than a deck row and
        // carries a hint, so on a narrow modal it wraps instead of
        // truncating the name.
        &--automatic {
            flex-wrap: wrap;

            .card__name {
                white-space: normal;
            }
        }
    }

    &__hint {
        opacity: 0.8;

        flex: none;

        font-size: smaller;
    }
}
</style>
