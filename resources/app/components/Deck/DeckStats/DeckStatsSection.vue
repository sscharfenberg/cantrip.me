<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { combineCI } from "@/utils/colorIdentity.ts";
import DeckStatsCategories from "Components/Deck/DeckStats/DeckStatsCategories.vue";
import DeckStatsColors from "Components/Deck/DeckStats/DeckStatsColors.vue";
import DeckStatsManacurve from "Components/Deck/DeckStats/DeckStatsManacurve.vue";
import DeckStatsManaSources from "Components/Deck/DeckStats/DeckStatsManaSources.vue";
import DeckStatsSpellPickerModal from "Components/Deck/DeckStats/DeckStatsSpellPickerModal.vue";
import Accordion from "Components/UI/Accordion.vue";
import Icon from "Components/UI/Icon.vue";
import { type HighlightColor, useDeckHighlight } from "Composables/useDeckHighlight.ts";
import { useDeckSort } from "Composables/useDeckSort.ts";
import {
    useDeckStats,
    type ColorPipTally,
    type KarstenColorAnalysis,
    type KarstenCombinedAnalysis
} from "Composables/useDeckStats.ts";
import { useManaSourcePicks } from "Composables/useManaSourcePicks.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion, DeckMeta } from "Types/deckPage.ts";
const props = defineProps<{
    cards: DeckCardRow[];
    commanders: DeckCommander[];
    companion: DeckCompanion | null;
    categories: DeckCategoryRow[];
    /**
     * The deck — its format drives Karsten's per-format mana-source
     * tables, its id keys the picked spells, and its sideboard / companion
     * rules shape the spell picker's groups as they do the text view's.
     */
    deck: DeckMeta;
}>();
const { t } = useI18n();
/**
 * The spell picked per "Mana sources" row, and which spell the rows
 * without a pick show — both remembered per deck in this browser.
 */
const { picks, setPick, clearPicks, automaticSpell, setAutomaticSpell } = useManaSourcePicks(props.deck.id);
/** The deck's sort mode — the spell picker lists its groups in the same order as the page. */
const { sortMode } = useDeckSort(props.deck.id);
const {
    manaCurve,
    averageManaValue,
    costPips,
    productionPips,
    karstenAnalysis,
    karstenCombined,
    karstenCommandZone,
    typeCounts,
    subtypeBreakdowns,
    categoryCounts
} = useDeckStats(
    () => props.cards,
    () => props.commanders,
    // As in the text view: a companion the format does not allow is not part of the deck.
    () => (props.deck.allows_companion ? props.companion : null),
    () => props.categories,
    () => props.deck.format,
    () => picks.value,
    () => automaticSpell.value
);
const { hasHighlight, clear, selectedSpellSources, setSpellSources } = useDeckHighlight();
/**
 * Strict gate for the color donut: hide when either ring would be
 * empty. A lone ring (e.g. all-colorless costs) is misleading because
 * the SVG center looks the same as a fully-populated chart, and the
 * chart's job is to compare cost against production at a glance.
 */
const tallyTotal = (t: ColorPipTally): number => t.W + t.U + t.B + t.R + t.G;
const showColors = computed(() => tallyTotal(costPips.value) > 0 && tallyTotal(productionPips.value) > 0);
/**
 * "Mana sources" stands on its own: a deck with no sources yet still
 * gets told how many each color needs.
 */
const showSources = computed(() => karstenAnalysis.value.length > 0 || karstenCombined.value.length > 0);

type KarstenRow = KarstenColorAnalysis | KarstenCombinedAnalysis;
/** A row's colors — its one color, or the combination. */
const rowColors = (row: KarstenRow): HighlightColor[] => ("color" in row ? [row.color] : row.colors);
/** The current row for a pick key, from either table; null once no card demands it any more. */
const findRow = (key: string): KarstenRow | null =>
    karstenAnalysis.value.find(row => row.key === key) ?? karstenCombined.value.find(row => row.key === key) ?? null;

/** Combined commander color identity, or null outside commander-like decks. */
const commanderIdentity = computed(() =>
    props.commanders.length === 0 ? null : combineCI(props.commanders.map(cmd => cmd.color_identity))
);
/**
 * The colors a row's highlight asks about: clamped to the commander
 * color identity, as the row's "have" is — an off-identity Island makes
 * no blue the row counts, so it must not light up either.
 */
const highlightColors = (row: KarstenRow): HighlightColor[] => {
    const identity = commanderIdentity.value;
    return identity === null ? rowColors(row) : rowColors(row).filter(c => identity.includes(c));
};

/** Key of the row whose spell picker is open, or null. */
const pickerKey = ref<string | null>(null);
const pickerRow = computed(() => (pickerKey.value === null ? null : findRow(pickerKey.value)));
/**
 * A row can vanish under an open picker — a reload after a card action
 * removes its last spell. The modal unmounts with it; forget the key too,
 * or the picker would pop open again once a card demands the row anew.
 */
watch(pickerRow, row => {
    if (row === null) pickerKey.value = null;
});

/** Key of the row whose spell's sources are highlighted, while they are. */
const highlightedKey = ref<string | null>(null);
watch(selectedSpellSources, selection => {
    if (selection === null) highlightedKey.value = null;
});
/**
 * Keep the highlight on the spell its row shows: a deck change can move
 * the row to another spell (the picked card left the deck, a quick-add
 * changed the automatic one), and a row can disappear. A snapshot taken
 * at pick time would go on pricing the old spell.
 */
watch(
    () => (highlightedKey.value === null ? null : findRow(highlightedKey.value)),
    row => {
        if (highlightedKey.value === null) return;
        if (row === null) {
            setSpellSources(null);
            return;
        }
        const colors = highlightColors(row);
        const current = selectedSpellSources.value;
        if (current !== null && current.mv === row.mv && current.colors.join("") === colors.join("")) return;
        setSpellSources({ colors, mv: row.mv });
    }
);

/** Template ref on the two color sections — where a row's spell button is found again for focus. */
const colorsRef = ref<HTMLElement | null>(null);

/**
 * Store the pick (null: back to automatic), then highlight the sources
 * of the spell the row now shows — read back from the recomputed row,
 * so an automatic choice is priced like a picked one. Focus returns to
 * the row's spell button: the picker that held it is gone, and so is a
 * reset button once the row is automatic again.
 */
const pick = async (key: string, oracleId: string | null): Promise<void> => {
    setPick(key, oracleId);
    const row = findRow(key);
    if (row !== null) {
        highlightedKey.value = key;
        setSpellSources({ colors: highlightColors(row), mv: row.mv });
    }
    await nextTick();
    colorsRef.value?.querySelector<HTMLElement>(`[data-key="${key}"] .spell-link`)?.focus();
};
/**
 * Return every row to its automatic spell. A highlighted row's sources
 * follow by themselves (see the watch above). The reset link disappears
 * with the picks, so focus moves to the automatic-spell choice beside it.
 */
const resetPicks = async (): Promise<void> => {
    clearPicks();
    await nextTick();
    colorsRef.value?.querySelector<HTMLElement>(".mana-sources__automatic button")?.focus();
};
const onPickerSelect = (oracleId: string | null): void => {
    const key = pickerKey.value;
    pickerKey.value = null;
    if (key !== null) void pick(key, oracleId);
};
</script>

<template>
    <accordion>
        <template #head
            ><span class="stats__hdl"><icon name="chart" />{{ t("pages.deck.stats.title") }}</span></template
        >
        <template #body>
            <div class="stats__body">
                <button v-if="hasHighlight" type="button" class="btn-default" @click="clear">
                    <icon name="close" />
                    {{ t("pages.deck.clear_selection") }}
                </button>
                <deck-stats-manacurve :curve="manaCurve" :average="averageManaValue" />
                <div v-if="showColors || showSources" ref="colorsRef" class="stats__colors">
                    <deck-stats-colors
                        v-if="showColors"
                        :cost="costPips"
                        :production="productionPips"
                        :sources-shown="showSources"
                    />
                    <deck-stats-mana-sources
                        v-if="showSources"
                        :karsten="karstenAnalysis"
                        :karsten-combined="karstenCombined"
                        :command-zone="karstenCommandZone"
                        :format="deck.format"
                        :automatic-spell="automaticSpell"
                        @pick="key => (pickerKey = key)"
                        @automatic-spell="setAutomaticSpell"
                        @reset-picks="() => void resetPicks()"
                    />
                </div>
                <deck-stats-spell-picker-modal
                    v-if="pickerKey !== null && pickerRow !== null"
                    :colors="rowColors(pickerRow)"
                    :candidates="pickerRow.candidates"
                    :picked="pickerRow.picked ? (picks[pickerKey] ?? null) : null"
                    :automatic="pickerRow.automatic"
                    :automatic-spell="automaticSpell"
                    :cards="cards"
                    :commanders="commanders"
                    :companion="deck.allows_companion ? companion : null"
                    :categories="categories"
                    :sort-mode="sortMode"
                    :allows-sideboard="deck.max_sideboard_size > 0"
                    @select="onPickerSelect"
                    @close="pickerKey = null"
                />
                <deck-stats-categories
                    :types="typeCounts"
                    :categories="categoryCounts"
                    :subtype-breakdowns="subtypeBreakdowns"
                />
            </div>
        </template>
    </accordion>
</template>

<style lang="scss" scoped>
@use "Abstracts/mixins" as m;

.stats {
    &__hdl {
        display: flex;
        align-items: center;

        gap: 0.5rem;
    }

    &__body {
        display: flex;
        flex-direction: column;

        gap: 1lh;

        container-type: inline-size;

        .btn-default {
            align-self: flex-start;
        }
    }

    // "Colored pips" and "Mana sources" stack, and sit side by side once
    // the panel is wide enough for both. Column flow rather than a fixed
    // two-column template, so a deck showing only one of them gives it
    // the full width.
    &__colors {
        display: grid;
        align-items: start;
        grid-template-columns: minmax(0, 1fr);

        gap: 1lh 2lh;

        @include m.cq("desktop") {
            grid-template-columns: none;
            grid-auto-columns: minmax(0, 1fr);
            grid-auto-flow: column;
        }
    }
}
</style>
