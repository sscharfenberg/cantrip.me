<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import FormGroup from "Components/Form/FormGroup.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import Headline from "Components/UI/Headline.vue";
import { useDeckHighlight } from "Composables/useDeckHighlight.ts";
import type { BreakdownBucket } from "Composables/useDeckStats.ts";
import type { DeckStatsSelection } from "Types/deckPage.ts";
const props = defineProps<{
    /** Additive type breakdown (Creature, Planeswalker, Battle, Artifact, Enchantment, Instant, Sorcery, Land). */
    types: BreakdownBucket[];
    /** User-defined category breakdown, sorted alphabetically; "Uncategorized" appended when present. */
    categories: BreakdownBucket[];
    /** Subtype buckets per card type. Keys are English type labels; values pre-sorted by count desc. */
    subtypeBreakdowns: Record<string, BreakdownBucket[]>;
}>();
const { t, te } = useI18n();
const { selectedCategory, setCategory } = useDeckHighlight();
type ViewKind = "types" | "categories" | "subtypes";
/** Active view in the first dropdown. */
const view = ref<ViewKind>("types");
/** Active card type in the second dropdown (only consulted when `view === 'subtypes'`). */
const subtypeCardType = ref<string>("");
/** Resolve a card type's English key to its localized plural label, or fall back to the key. */
const localizeType = (key: string): string => (te(`enums.card_types.${key}`) ? t(`enums.card_types.${key}`) : key);
/** Resolve a subtype bucket label — `__no_subtype` is a sentinel that swaps to a localized "No subtype" string. */
const localizeBucket = (bucket: BreakdownBucket): string =>
    bucket.label === "__no_subtype" ? t("pages.deck.stats.categories.no_subtype") : bucket.label;
/** Card types present in the deck — only those with at least one card show up in the second dropdown. */
const availableSubtypeCardTypes = computed(() => Object.keys(props.subtypeBreakdowns));
/**
 * Threshold above which subtype / category bars don't fit comfortably
 * as side-by-side columns at landscape+ (label collisions, label
 * truncation). Creature subtypes routinely blow past this; user-defined
 * categories can too. Types (always 8) and other subtypes (Land,
 * Artifact, …) stay below it. Tuned by eye — bump if labels start
 * crowding before the threshold trips.
 */
const HORIZONTAL_BUCKET_THRESHOLD = 6;
/**
 * Force the one-bar-per-row layout when the active slice has too many
 * buckets to fit as columns. Only applied to the user-driven slices
 * (subtypes, custom categories); the fixed type breakdown is sized to
 * fit and stays as columns regardless.
 */
const forceHorizontal = computed<boolean>(() => {
    if (view.value !== "subtypes" && view.value !== "categories") return false;
    return buckets.value.length > HORIZONTAL_BUCKET_THRESHOLD;
});
/**
 * True when the deck has at least one user-defined category that
 * contains cards. The synthetic "Uncategorized" bucket (keyed
 * `__uncategorized` by the composable) doesn't count — it's always
 * present whenever cards lack a category, which is most decks.
 */
const hasUserCategories = computed(() => props.categories.some(b => b.key !== "__uncategorized"));
/**
 * First-dropdown options. "Custom categories" only appears when the
 * deck actually has any user-defined categories with members. Order is
 * preserved (no MonoSelect alphabetical sort) so the choices read in
 * the order the user specified them.
 */
const viewOptions = computed(() => {
    const options: { value: ViewKind; label: string }[] = [
        { value: "types", label: t("pages.deck.stats.categories.view.types") }
    ];
    if (hasUserCategories.value) {
        options.push({ value: "categories", label: t("pages.deck.stats.categories.view.categories") });
    }
    options.push({ value: "subtypes", label: t("pages.deck.stats.categories.view.subtypes") });
    return options;
});
/** Second-dropdown options — populated only when `view === 'subtypes'`. */
const subtypeCardTypeOptions = computed(() =>
    availableSubtypeCardTypes.value.map(key => ({ value: key, label: localizeType(key) }))
);
/**
 * The bucket list to render. Picks the appropriate slice based on
 * the current `view`. Empty array when no slice is available (e.g.
 * `subtypes` selected but no card type picked yet).
 */
const buckets = computed<BreakdownBucket[]>(() => {
    if (view.value === "types") return props.types;
    if (view.value === "categories") return props.categories;
    if (view.value === "subtypes" && subtypeCardType.value) {
        return props.subtypeBreakdowns[subtypeCardType.value] ?? [];
    }
    return [];
});
/**
 * Per-bar text alternative for screen readers. The visible chart is
 * decorative; this carries the data.
 */
const ariaFor = (bucket: BreakdownBucket): string =>
    t("pages.deck.stats.categories.bar_aria", {
        label: localizeBucket(bucket),
        count: bucket.count,
        percent: bucket.percent.toFixed(1)
    });
/** Tallest bucket — divisor for relative bar fractions. Floored at 1 to avoid div-by-zero. */
const max = computed(() => Math.max(1, ...buckets.value.map(b => b.count)));
/**
 * Convert the panel's "this bucket clicked, in this view" state into
 * the discriminated union the parent (and ultimately the card views)
 * consumes for matching. Sentinel keys (`__uncategorized`,
 * `__no_subtype`) become `null` so the matchers can compare cleanly
 * against `card.category_id` / "no subtypes after the em-dash".
 */
const selectionFor = (bucket: BreakdownBucket): DeckStatsSelection | null => {
    if (view.value === "types") return { kind: "type", label: bucket.label };
    if (view.value === "categories") {
        return { kind: "category", id: bucket.key === "__uncategorized" ? null : bucket.key };
    }
    if (view.value === "subtypes" && subtypeCardType.value) {
        return {
            kind: "subtype",
            cardType: subtypeCardType.value,
            subtype: bucket.key === "__no_subtype" ? null : bucket.key
        };
    }
    return null;
};
/** True when a given bar is the one currently reflected in the active highlight. */
const isBarSelected = (bucket: BreakdownBucket): boolean => {
    const sel = selectedCategory.value;
    if (sel === null) return false;
    if (sel.kind === "type" && view.value === "types") return sel.label === bucket.label;
    if (sel.kind === "category" && view.value === "categories") {
        const id = bucket.key === "__uncategorized" ? null : bucket.key;
        return sel.id === id;
    }
    if (sel.kind === "subtype" && view.value === "subtypes") {
        if (sel.cardType !== subtypeCardType.value) return false;
        const subtype = bucket.key === "__no_subtype" ? null : bucket.key;
        return sel.subtype === subtype;
    }
    return false;
};
/** Toggle on click: the active bar clears the highlight, a different bar replaces it. */
const onBarClick = (bucket: BreakdownBucket): void => {
    setCategory(isBarSelected(bucket) ? null : selectionFor(bucket));
};
/** When the user picks "subtypes" without a card type yet, default to the first available one. */
watch(
    () => [view.value, availableSubtypeCardTypes.value] as const,
    ([currentView, available]) => {
        if (currentView !== "subtypes") return;
        if (subtypeCardType.value && available.includes(subtypeCardType.value)) return;
        subtypeCardType.value = available[0] ?? "";
    },
    { immediate: true }
);
/** Fall back to "types" if the user lands on a view that's no longer available (e.g. categories cleared). */
watch(
    () => viewOptions.value.map(o => o.value),
    available => {
        if (!available.includes(view.value)) view.value = "types";
    }
);
/**
 * Clear the active selection whenever the user navigates away from
 * the slice it belonged to (changing view, switching subtype card
 * type). Avoids a stale highlight that no longer corresponds to any
 * visible bar.
 */
watch([view, subtypeCardType], () => {
    if (selectedCategory.value !== null) setCategory(null);
});
</script>

<template>
    <section class="stats" :class="{ 'stats--horizontal': forceHorizontal }">
        <headline :size="4">{{ t("pages.deck.stats.categories.title") }}</headline>
        <form-group :label="t('pages.deck.stats.categories.view_picker')">
            <mono-select
                :options="viewOptions"
                :selected="view"
                :placeholder="t('pages.deck.stats.categories.view_picker')"
                :sort="false"
                :clearable="false"
                @change="value => (view = value as ViewKind)"
            />
        </form-group>
        <form-group
            v-if="view === 'subtypes' && subtypeCardTypeOptions.length"
            :label="t('pages.deck.stats.categories.subtype_picker')"
            style="margin-top: 0.5rem"
        >
            <mono-select
                :options="subtypeCardTypeOptions"
                :selected="subtypeCardType"
                :placeholder="t('pages.deck.stats.categories.subtype_picker')"
                :clearable="false"
                @change="value => (subtypeCardType = value)"
            />
        </form-group>
        <ol v-if="buckets.length" class="stats__bars">
            <li v-for="bucket in buckets" :key="bucket.key" class="stats__bar">
                <button
                    type="button"
                    class="stats__btn"
                    :class="{ 'stats__btn--selected': isBarSelected(bucket) }"
                    :aria-label="ariaFor(bucket)"
                    :aria-pressed="isBarSelected(bucket)"
                    @click="onBarClick(bucket)"
                >
                    <span class="stats__label">{{ localizeBucket(bucket) }}</span>
                    <span class="stats__track" aria-hidden="true">
                        <span class="stats__fill" :style="{ '--frac': bucket.count / max }"></span>
                    </span>
                    <span class="stats__count">{{ bucket.count }}</span>
                    <span class="stats__percent">{{ bucket.percent.toFixed(1) }}%</span>
                </button>
            </li>
        </ol>
    </section>
</template>

<style lang="scss" scoped>
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

// Default layout: horizontal bars — each row is [label, track, count, percent].
// Bars share a single grid (li and button both pass through via display: contents
// / subgrid) so columns line up vertically across rows. At landscape+ the layout
// flips to vertical columns matching the mana curve, EXCEPT when the parent
// section carries `--horizontal` (e.g. creature subtypes — too many buckets to
// fit side-by-side as columns).
.stats {
    &__bars {
        display: grid;
        align-items: center;
        grid-template-columns: minmax(6rem, max-content) 1fr min-content min-content;

        padding: map.get(s.$components, "deck-categories", "gap") 0 0;
        margin: #{map.get(s.$components, "deck-categories", "gap") * 2} 0 0;
        gap: map.get(s.$components, "deck-categories", "gap");

        list-style: none;
    }

    &__bar {
        // Pass through so the inner button can subgrid against the
        // parent ol's columns.
        display: contents;
    }

    &__btn {
        // Subgrid against `__bars` so all rows share the same four-column
        // track and the count / percent columns line up across bars
        // regardless of digit count.
        display: grid;
        align-items: center;
        grid-template-columns: subgrid;
        grid-column: 1 / -1;

        padding: 0;
        border: 0;
        gap: map.get(s.$components, "deck-categories", "gap");

        background: transparent;

        text-align: left;

        cursor: pointer;

        // Hover paints the fill. The selected rule chains the parent class
        // (`&--selected#{&}` → `.stats__btn--selected.stats__btn`) plus the
        // always-present `[type]` attribute, putting its specificity at
        // (0,6,0) in scoped CSS — one step above the hover rule's (0,5,0).
        // That way a selected bar keeps its selected colors even while
        // hovered, regardless of cascade order or scoped-CSS quirks around
        // `:not()`.
        &:hover .stats__fill {
            background: map.get(c.$components, "deck-categories", "hover", "background");
            border-color: map.get(c.$components, "deck-categories", "hover", "border");
        }

        &--selected#{&}[type] .stats__fill {
            background: map.get(c.$components, "deck-categories", "selected", "background");
            border-color: map.get(c.$components, "deck-categories", "selected", "border");
        }
    }

    &__track {
        display: block;
        position: relative;

        overflow: hidden;

        width: 100%;
        height: map.get(s.$components, "deck-categories", "track-height");
    }

    &__fill {
        display: block;

        width: calc(var(--frac, 0) * 100%);
        height: 100%;
        border: map.get(s.$components, "deck-categories", "border") solid
            map.get(c.$components, "deck-categories", "bar", "border");

        background: map.get(c.$components, "deck-categories", "bar", "background");
        border-radius: map.get(s.$components, "deck-categories", "radius");

        transition:
            width map.get(ti.$timings, "fast") ease,
            height map.get(ti.$timings, "fast") ease;

        @media (prefers-reduced-motion: reduce) {
            transition: none;
        }
    }

    &__count,
    &__percent {
        font-variant-numeric: tabular-nums;
    }

    // Landscape+: flip to vertical columns. Skipped when `--horizontal` is set
    // so callers with too many buckets (creature subtypes) keep one-bar-per-row.
    &:not(&--horizontal) {
        @include m.mq("landscape") {
            .stats__bars {
                align-items: stretch;
                grid-template-columns: none;
                grid-auto-columns: 1fr;
                grid-auto-flow: column;

                height: map.get(s.$components, "deck-categories", "height");
                gap: #{map.get(s.$components, "deck-categories", "gap") * 2};
            }

            .stats__bar {
                display: flex;

                min-width: 0;
            }

            .stats__btn {
                grid-template-columns: none;
                grid-template-rows: auto 1fr auto auto;
                grid-column: auto;
                justify-items: center;

                width: 100%;
                height: 100%;

                text-align: center;
            }

            .stats__label {
                order: 3;
            }

            .stats__track {
                order: 2;
                place-self: stretch stretch;

                width: 100%;
                height: 100%;
            }

            .stats__fill {
                position: absolute;
                inset: auto 0 0;

                width: 100%;
                height: calc(var(--frac, 0) * 100%);
            }

            .stats__count {
                order: 1;
            }

            .stats__percent {
                order: 4;
            }
        }

        @include m.mq("desktop") {
            .stats__bars {
                gap: #{map.get(s.$components, "deck-categories", "gap") * 4};
            }
        }
    }
}
</style>
