import type { ComputedRef, MaybeRefOrGetter, Ref } from "vue";
import { computed, toValue } from "vue";
import { compareCards, useDeckGrouping } from "Composables/useDeckGrouping.ts";
import type { DeckCardGroup } from "Composables/useDeckGrouping.ts";
import type { DeckSort } from "Composables/useDeckSort.ts";
import type { DeckCardRow, DeckCategoryRow, DeckCommander, DeckCompanion } from "Types/deckPage";

/**
 * A unified card group — either a default type group or a custom category.
 *
 * This common shape lets the template render both kinds identically.
 * `categoryId` discriminates: null means a default type group whose
 * membership is derived from the card's type line; non-null means a
 * user-defined category stored in `deck_categories`.
 */
export interface CardSection {
    /** Group identifier — a {@link DeckCardGroup} for defaults, `cat-{uuid}` for categories. */
    key: string;
    /** Display label — i18n-resolved for defaults, raw name for categories. */
    label: string;
    /** Cards belonging to this group, sorted by the active sort mode. */
    cards: DeckCardRow[];
    /** Sum of `quantity` across all cards — may differ from `cards.length` for multi-copy entries. */
    count: number;
    /** Non-null for custom categories — used as the drop target category ID. */
    categoryId: string | null;
}

/**
 * Discriminated union for visual sections in the column layout.
 *
 * Commanders sit outside the drag system (no dragging in/out). The
 * `create-group` variant only appears while a drag is in progress,
 * giving the user a drop target to spawn a new custom category.
 */
export type Section =
    | { kind: "commanders"; commanders: DeckCommander[] }
    | { kind: "companion"; companion: DeckCompanion }
    | { kind: "group"; group: CardSection }
    | { kind: "create-group" };

/** Return type of {@link useDeckSections}. */
export type UseDeckSectionsReturn = {
    /** All card groups (default type groups + custom categories), sorted alphabetically. */
    allGroups: ComputedRef<CardSection[]>;
    /** Flat list of visual sections ready for column distribution. */
    sections: ComputedRef<Section[]>;
    /**
     * Extra drop-target sections that appear only during a drag. These are
     * kept separate from `sections` so that starting a drag does not change
     * the column distribution (which would destroy the active VueDraggable
     * and swallow the `@end` event). Includes empty custom categories and
     * placeholder default groups for the dragged card's type.
     */
    dragTargets: ComputedRef<CardSection[]>;
};

/**
 * Merge default type groups and custom categories into a single alphabetically
 * sorted list, then wrap them into visual sections (commanders, groups,
 * create-group drop target).
 *
 * `allGroups` and `sections` are deliberately free of any drag-state
 * dependency. Changing sections mid-drag would trigger a column
 * redistribution in `useResponsiveColumns`, which destroys the active
 * VueDraggable instance and swallows the `@end` event. Extra drop targets
 * that only appear during a drag are returned separately via `dragTargets`.
 *
 * @param cards - All deck cards.
 * @param commanders - Command zone cards.
 * @param companion - The deck's "Companion" keyword card, or null when not set.
 * @param categories - User-defined categories.
 * @param sortMode - Active sort mode within groups.
 * @param translate - i18n translate function for default group labels.
 * @param draggedTypeGroup - Ref to the type group of the card being dragged.
 */
export function useDeckSections(
    cards: MaybeRefOrGetter<DeckCardRow[]>,
    commanders: MaybeRefOrGetter<DeckCommander[]>,
    companion: MaybeRefOrGetter<DeckCompanion | null>,
    categories: MaybeRefOrGetter<DeckCategoryRow[]>,
    sortMode: MaybeRefOrGetter<DeckSort>,
    translate: (key: string) => string,
    draggedTypeGroup: Ref<DeckCardGroup | null>
): UseDeckSectionsReturn {
    const { groups: typeGroups } = useDeckGrouping(
        () => toValue(cards).filter(c => c.category_id === null),
        sortMode
    );

    const allGroups = computed<CardSection[]>(() => {
        const result: CardSection[] = [];

        for (const g of typeGroups.value) {
            result.push({
                key: g.group,
                label: translate(`pages.deck.groups.${g.group}`),
                cards: g.cards,
                count: g.count,
                categoryId: null,
            });
        }

        const catBuckets = new Map<string, CardSection>();
        const allCards = toValue(cards);
        const allCategories = toValue(categories);

        for (const cat of allCategories) {
            catBuckets.set(cat.id, { key: `cat-${cat.id}`, label: cat.name, cards: [], count: 0, categoryId: cat.id });
        }
        for (const card of allCards) {
            if (card.category_id === null) continue;
            const bucket = catBuckets.get(card.category_id);
            if (bucket) {
                bucket.cards.push(card);
                bucket.count += card.quantity;
            }
        }

        const comparator = compareCards(toValue(sortMode));
        for (const bucket of catBuckets.values()) {
            if (bucket.cards.length > 0) {
                bucket.cards.sort(comparator);
                result.push(bucket);
            }
        }

        result.sort((a, b) => a.label.localeCompare(b.label));
        return result;
    });

    const sections = computed<Section[]>(() => {
        const result: Section[] = [];
        const cmds = toValue(commanders);
        if (cmds.length > 0) {
            result.push({ kind: "commanders", commanders: cmds });
        }
        const cmp = toValue(companion);
        if (cmp !== null) {
            result.push({ kind: "companion", companion: cmp });
        }
        for (const group of allGroups.value) {
            result.push({ kind: "group", group });
        }
        // Always present in the layout so that starting a drag doesn't
        // trigger a column redistribution (which would destroy the active
        // VueDraggable instance and swallow the @end event). Visibility
        // is controlled via v-if in the template instead.
        result.push({ kind: "create-group" });
        return result;
    });

    /**
     * Extra drop-target sections that appear only during a drag. Computed
     * separately from `sections` so that the column layout stays stable.
     *
     * Includes: empty custom categories (so every category is reachable)
     * and a placeholder default type group when all cards of the dragged
     * card's type are in custom categories.
     */
    const dragTargets = computed<CardSection[]>(() => {
        const dtg = draggedTypeGroup.value;
        if (!dtg) return [];

        const targets: CardSection[] = [];
        const presentKeys = new Set(allGroups.value.map(g => g.key));

        // Empty custom categories not already shown in allGroups.
        for (const cat of toValue(categories)) {
            const key = `cat-${cat.id}`;
            if (!presentKeys.has(key)) {
                targets.push({ key, label: cat.name, cards: [], count: 0, categoryId: cat.id });
            }
        }

        // Placeholder default type group when all cards of that type are
        // in custom categories (so the group doesn't appear in allGroups).
        if (!presentKeys.has(dtg)) {
            targets.push({ key: dtg, label: translate(`pages.deck.groups.${dtg}`), cards: [], count: 0, categoryId: null });
        }

        targets.sort((a, b) => a.label.localeCompare(b.label));
        return targets;
    });

    return { allGroups, sections, dragTargets };
}