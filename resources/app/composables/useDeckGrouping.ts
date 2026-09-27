import type { ComputedRef, MaybeRefOrGetter } from "vue";
import { computed, toValue } from "vue";
import { compareCards, GROUP_ORDER, resolveGroup } from "@/utils/deckGrouping";
import type { DeckCardGroup } from "@/utils/deckGrouping";
import type { DeckSort } from "Composables/useDeckSort.ts";
import type { DeckCardRow } from "Types/deckPage";

/** The card fields grouping and sorting read — a full `DeckCardRow` satisfies it. */
export type GroupableCard = Pick<DeckCardRow, "name" | "cmc" | "type_line" | "quantity">;

/** A group of deck cards sharing a primary card type. */
export type DeckCardGrouping<T extends GroupableCard = DeckCardRow> = {
    /** Primary card type for this group. */
    group: DeckCardGroup;
    /** Cards belonging to the group, ordered by the active sort mode. */
    cards: T[];
    /** Sum of quantities of the cards in this group. */
    count: number;
};

/** Return type of {@link useDeckGrouping}. */
export type UseDeckGroupingReturn<T extends GroupableCard = DeckCardRow> = {
    /** Non-empty groups in canonical display order. */
    groups: ComputedRef<DeckCardGrouping<T>[]>;
};

/**
 * Group a reactive list of deck cards by their primary card type.
 *
 * The input can be a ref, a getter, or a plain array — `toValue` normalises
 * it so callers can pass `props.cards` directly. Groups are returned in the
 * canonical display order defined by {@link GROUP_ORDER}, with empty groups
 * omitted so consumers can `v-for` without guarding.
 *
 * @param cards - Deck cards to group. Accepts any `MaybeRefOrGetter<DeckCardRow[]>`.
 * @param sortMode - Sort order within each group. Defaults to mana value.
 * @returns Reactive list of non-empty groups.
 */
export function useDeckGrouping<T extends GroupableCard = DeckCardRow>(
    cards: MaybeRefOrGetter<T[]>,
    sortMode: MaybeRefOrGetter<DeckSort> = () => "mana"
): UseDeckGroupingReturn<T> {
    const groups = computed<DeckCardGrouping<T>[]>(() => {
        const comparator = compareCards<T>(toValue(sortMode));
        const buckets = new Map<DeckCardGroup, DeckCardGrouping<T>>();
        for (const card of toValue(cards)) {
            const group = resolveGroup(card.type_line);
            let bucket = buckets.get(group);
            if (bucket === undefined) {
                bucket = { group, cards: [], count: 0 };
                buckets.set(group, bucket);
            }
            bucket.cards.push(card);
            bucket.count += card.quantity;
        }
        for (const bucket of buckets.values()) {
            bucket.cards.sort(comparator);
        }
        return GROUP_ORDER.map(group => buckets.get(group)).filter(
            (bucket): bucket is DeckCardGrouping<T> => bucket !== undefined
        );
    });

    return { groups };
}
