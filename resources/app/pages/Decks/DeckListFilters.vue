<script setup lang="ts">
/**
 * Badge filters inside a deck-list format folder: deck state, Commander
 * Bracket, visibility. Always visible, one row on a wide screen.
 *
 * Each badge is a toggle button: a click selects its value (replacing the
 * group's previous one), a click on the active badge clears the group. The
 * active badge takes the colour the same badge has in the deck rows, turns
 * bold, and swaps its icon for a check mark — so the selection does not rely
 * on colour alone, and the badge keeps its width.
 *
 * A group only shows when it can narrow the folder (two values to choose
 * between) or holds an active value that must stay switchable.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import {
    DECK_FILTER_KEYS,
    type DeckFilterKey,
    type DeckFilters,
    type FilterableDeck,
    NO_BRACKET,
    deckFilterOptions,
    filterDecks,
    hasDeckFilters,
    isDeckFilterUseful,
    toggleDeckFilter
} from "@/utils/deckListFilters.ts";
import Icon from "Components/UI/Icon.vue";
const props = defineProps<{
    /** Every deck in the folder, filtered or not. */
    decks: FilterableDeck[];
}>();
/** The active filters; `null` per group means "show all". */
const filters = defineModel<DeckFilters>({ required: true });
const { t } = useI18n();
interface FilterBadge {
    value: string;
    text: string;
    icon: string;
    /** Badge type of the active state — the colour this value has in the deck rows. */
    type: "info" | "success" | "warning" | "error";
    /** Accessible name when the visible text alone says too little (a bare bracket number). */
    ariaLabel?: string;
    tooltip?: string;
    active: boolean;
}
interface FilterGroup {
    key: DeckFilterKey;
    label: string;
    badges: FilterBadge[];
}
const STATE_TYPES: Record<string, FilterBadge["type"]> = { planned: "info", built: "success", archived: "warning" };
const STATE_ICONS: Record<string, string> = { planned: "planned", built: "finished", archived: "archived" };
/** One badge's looks and words, by group and value. */
const describe = (key: DeckFilterKey, value: string): Omit<FilterBadge, "value" | "active"> => {
    if (key === "state") {
        return { text: t(`enums.deck_state.${value}`), icon: STATE_ICONS[value] ?? "deck", type: STATE_TYPES[value] ?? "info" };
    }
    if (key === "bracket") {
        const label = t(value === NO_BRACKET ? "form.fields.deck_bracket_unset" : `form.fields.deck_bracket_${value}`);
        return { text: value === NO_BRACKET ? "–" : value, icon: "swords", type: "info", ariaLabel: label, tooltip: label };
    }
    return {
        text: t(`enums.visibility.${value}`),
        icon: value === "private" ? "visibility-off" : "visibility-on",
        type: value === "private" ? "success" : "error"
    };
};
const GROUP_LABELS: Record<DeckFilterKey, string> = {
    state: "pages.decks.filters.state",
    bracket: "form.fields.deck_bracket",
    visibility: "pages.deck.visibility"
};
const groups = computed<FilterGroup[]>(() =>
    DECK_FILTER_KEYS.flatMap(key => {
        const active = filters.value[key];
        const options = deckFilterOptions(props.decks, key, active);
        if (!isDeckFilterUseful(options, active)) return [];
        return [
            {
                key,
                label: t(GROUP_LABELS[key]),
                badges: options.map(value => ({ value, active: value === active, ...describe(key, value) }))
            }
        ];
    })
);
const isFiltered = computed(() => hasDeckFilters(filters.value));
const shown = computed(() => filterDecks(props.decks, filters.value).length);
const toggle = (key: DeckFilterKey, value: string): void => {
    filters.value = toggleDeckFilter(filters.value, key, value);
};
/**
 * What the live region reads out. Set only when the filters change, so
 * opening a folder announces nothing.
 */
const announcement = ref("");
watch(
    filters,
    () => {
        announcement.value = t("pages.decks.filters.shown", { shown: shown.value, total: props.decks.length });
    },
    { deep: true }
);
</script>

<template>
    <div v-if="groups.length" class="deck-filters" role="group" :aria-label="t('pages.decks.filters.label')">
        <div
            v-for="group in groups"
            :key="group.key"
            class="deck-filters__group"
            role="group"
            :aria-label="group.label"
        >
            <button
                v-for="badge in group.badges"
                :key="badge.value"
                v-tooltip="badge.tooltip"
                type="button"
                class="badge deck-filters__badge"
                :class="badge.active ? ['deck-filters__badge--active', badge.type] : []"
                :aria-pressed="badge.active"
                :aria-label="badge.ariaLabel"
                @click="toggle(group.key, badge.value)"
            >
                <icon :name="badge.active ? 'check' : badge.icon" :size="1" />
                <span>{{ badge.text }}</span>
            </button>
        </div>
        <span v-if="isFiltered" class="deck-filters__count" aria-hidden="true">{{ shown }} / {{ decks.length }}</span>
        <span class="sr-only" aria-live="polite">{{ announcement }}</span>
    </div>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;

.deck-filters {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    margin-bottom: 1ex;
    gap: 0.75ex 2ch;

    &__group {
        display: flex;
        flex-wrap: wrap;

        gap: 0.5ch;
    }

    // Smaller than a status badge: these are controls in a dense row, and
    // the global badge's padding would double the row's height.
    &__badge {
        padding: 0.3ex 0.8ch;

        font-size: 0.875rem;

        cursor: pointer;

        // Unselected: neutral and quiet. Scoped styles beat the layered
        // `.badge.info` & co., so the active badge must be excluded here or
        // it would lose its colour too.
        &:not(.deck-filters__badge--active) {
            opacity: 0.7;

            background-color: transparent;
            color: inherit;
            border-color: map.get(c.$components, "input", "border");

            font-weight: 400;
        }

        // A ring in the badge's own border colour doubles the border, so a
        // selected "info" badge — whose fill is close to the unselected look —
        // still stands out. Unselected badges cannot simply be dimmed further:
        // their text would drop below WCAG contrast.
        @each $type in ("info", "success", "warning", "error") {
            &--active.#{$type} {
                box-shadow: 0 0 0 1px map.get(c.$state, $type, "border");
            }
        }

        &:hover,
        &:focus-visible {
            opacity: 1;
        }

        &:focus-visible {
            outline: 2px solid currentcolor;
            outline-offset: 2px;
        }
    }

    &__count {
        opacity: 0.8;

        margin-left: auto;

        font-size: 0.875rem;
        white-space: nowrap;
    }
}
</style>
