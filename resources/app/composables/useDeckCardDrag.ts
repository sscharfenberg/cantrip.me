import { router } from "@inertiajs/vue3";
import type { Ref } from "vue";
import { ref } from "vue";
import { resolveGroup } from "@/utils/deckGrouping";
import type { DeckCardGroup } from "@/utils/deckGrouping";
import type { CardSection } from "Composables/useDeckSections.ts";
import type { DeckCardRow } from "Types/deckPage";

/** Return type of {@link useDeckCardDrag}. */
export type UseDeckCardDragReturn = {
    /** True while a card is being dragged. */
    dragging: Ref<boolean>;
    /** The type group key of the card currently being dragged, or null when idle. */
    draggedTypeGroup: Ref<DeckCardGroup | null>;
    /** Called when a drag starts — tracks which type group the card belongs to. */
    onDragStart: (evt: { item: HTMLElement }) => void;
    /** Called when a drag ends without a valid drop. */
    onDragEnd: () => void;
    /** Returns true when this section is NOT a valid drop target for the current drag. */
    isUnavailable: (section: CardSection) => boolean;
    /** Returns the SortableJS group config for a card group section. */
    groupFor: (section: CardSection) => { name: string; pull: "clone"; put: boolean | ((to: unknown, from: unknown, dragEl: HTMLElement) => boolean) };
    /** Items dropped on the "create new group" target. */
    dropTargetList: Ref<DeckCardRow[]>;
    /** SortableJS group config for the "create new group" drop target. */
    createGroupTarget: { name: string; pull: false; put: true };
    /** True when the "create group" modal should be shown. */
    showCreateGroupModal: Ref<boolean>;
    /** The card that was dropped on the "create new group" target. */
    droppedCard: Ref<DeckCardRow | null>;
    /** Called when a card is dropped on the "create new group" target. */
    onDropToCreateGroup: () => void;
    /** Called when a card is dropped onto a group (custom category or matching default). */
    onDropToGroup: (evt: { item: HTMLElement }, categoryId: string | null) => void;
};

/**
 * Manages all drag-and-drop state and behaviour for deck card views.
 *
 * Cards are dragged between groups using SortableJS (via vue-draggable-plus).
 * `pull: "clone"` is used everywhere so the source list is never mutated
 * by the drag library — the actual state change comes from the server
 * response after the PATCH request.
 *
 * Drop targets vary by section type:
 * - Custom categories always accept drops.
 * - Default type groups only accept a card whose type matches the group,
 *   so you can drag a card back from a custom category to its natural group.
 * - The "create group" target accepts any card and opens a modal to name
 *   the new category.
 *
 * @param deckId - UUID of the deck (used to build the PATCH URL).
 * @param cards - Getter for all deck cards — needed to look up card data
 *   from the DOM element that SortableJS provides on drop events.
 */
export function useDeckCardDrag(
    deckId: string,
    cards: () => DeckCardRow[]
): UseDeckCardDragReturn {
    const dragging = ref(false);
    const draggedTypeGroup = ref<DeckCardGroup | null>(null);

    /**
     * Resolve a card from a SortableJS DOM element. SortableJS only gives us
     * the dragged `HTMLElement`, so we read the `data-card-id` attribute and
     * look up the full card object from the reactive cards list.
     */
    function findCard(el: HTMLElement): DeckCardRow | undefined {
        const id = el.dataset.cardId;
        return id ? cards().find(c => c.id === id) : undefined;
    }

    /**
     * Track the dragged card's type group on drag start. This drives two
     * reactive effects: `useDeckSections` injects a placeholder group if
     * that type is missing, and `isUnavailable` dims non-matching groups.
     */
    function onDragStart(evt: { item: HTMLElement }): void {
        dragging.value = true;
        const card = findCard(evt.item);
        draggedTypeGroup.value = card ? resolveGroup(card.type_line) : null;
    }

    /** Reset drag state. Called when a drag is cancelled (no valid drop). */
    function onDragEnd(): void {
        dragging.value = false;
        draggedTypeGroup.value = null;
    }

    /**
     * Check whether a section should be visually dimmed during a drag.
     * Custom categories are always valid targets. Default groups are only
     * valid when their type matches the dragged card's type.
     */
    function isUnavailable(section: CardSection): boolean {
        if (!dragging.value) return false;
        if (section.categoryId) return false;
        return section.key !== draggedTypeGroup.value;
    }

    /**
     * Build the SortableJS `group` config for a card group section.
     *
     * `pull: "clone"` prevents SortableJS from mutating the source array —
     * the real move happens server-side via the PATCH in `onDropToGroup`.
     *
     * For default groups, `put` is a function that checks the dragged card's
     * type at drop time, ensuring only type-matching cards are accepted.
     */
    function groupFor(section: CardSection) {
        return {
            name: "deck-cards",
            pull: "clone" as const,
            put: section.categoryId
                ? true
                : (_to: unknown, _from: unknown, dragEl: HTMLElement) => {
                      const card = findCard(dragEl);
                      return card ? resolveGroup(card.type_line) === section.key : false;
                  },
        };
    }

    // --- "Create new group" flow ---
    // When a card is dropped on the create-group target, SortableJS adds it
    // to `dropTargetList`. The `@add` handler extracts the card, clears the
    // list (so SortableJS doesn't render a duplicate), and opens the modal
    // for the user to name the new category.

    /** Temporary list that SortableJS populates on drop — immediately drained by the handler. */
    const dropTargetList = ref<DeckCardRow[]>([]);
    /** SortableJS group config for the "create new group" drop target. */
    const createGroupTarget = { name: "deck-cards", pull: false as const, put: true as const };
    /** Controls visibility of the DeckAddGroupModal. */
    const showCreateGroupModal = ref(false);
    /** The card that triggered group creation — passed to the modal as context. */
    const droppedCard = ref<DeckCardRow | null>(null);

    /** Extract the dropped card from the SortableJS list and open the naming modal. */
    function onDropToCreateGroup(): void {
        dragging.value = false;
        draggedTypeGroup.value = null;
        if (dropTargetList.value.length > 0) {
            droppedCard.value = dropTargetList.value[0];
            dropTargetList.value = [];
            showCreateGroupModal.value = true;
        }
    }

    /**
     * Persist a category change via Inertia PATCH. Passing `category_id: null`
     * removes the card from any custom category, returning it to its default
     * type group.
     */
    function onDropToGroup(evt: { item: HTMLElement }, categoryId: string | null): void {
        dragging.value = false;
        draggedTypeGroup.value = null;
        const cardId = evt.item.dataset.cardId;
        if (!cardId) return;
        router.patch(`/api/decks/${deckId}/cards/${cardId}/category`, { category_id: categoryId }, { preserveScroll: true });
    }

    return {
        dragging,
        draggedTypeGroup,
        onDragStart,
        onDragEnd,
        isUnavailable,
        groupFor,
        dropTargetList,
        createGroupTarget,
        showCreateGroupModal,
        droppedCard,
        onDropToCreateGroup,
        onDropToGroup,
    };
}