import { describe, expect, it, vi } from "vitest";
import { makeDeckCard } from "@/test/factories/deckCard.ts";
import { routerMock } from "@/test/inertia.ts";
import { useDeckCardDrag } from "../useDeckCardDrag.ts";
import type { CardSection } from "../useDeckSections.ts";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const DECK_ID = "deck-1";

const bear = makeDeckCard({ id: "card-bear", name: "Grizzly Bears", type_line: "Creature — Bear" });
const island = makeDeckCard({ id: "card-island", name: "Island", type_line: "Basic Land — Island" });
const CARDS = [bear, island];

/** The DOM element SortableJS hands back, carrying the card id it was given. */
const draggedElement = (cardId?: string): HTMLElement => {
    const element = document.createElement("li");
    if (cardId !== undefined) element.dataset.cardId = cardId;
    return element;
};

/** A section of the kind `useDeckSections` produces. */
const sectionOf = (overrides: Partial<CardSection> = {}): CardSection => ({
    key: "creature",
    label: "Creature",
    cards: [],
    count: 0,
    categoryId: null,
    zone: "main",
    ...overrides
});

const setup = () => useDeckCardDrag(DECK_ID, () => CARDS);

describe("useDeckCardDrag — drag lifecycle", () => {
    it("starts idle", () => {
        const drag = setup();

        expect(drag.dragging.value).toBe(false);
        expect(drag.draggedTypeGroup.value).toBeNull();
    });

    it("records the dragged card's type group on drag start", () => {
        const drag = setup();

        drag.onDragStart({ item: draggedElement("card-island") });

        expect(drag.dragging.value).toBe(true);
        expect(drag.draggedTypeGroup.value).toBe("land");
    });

    it("still enters the dragging state for an element it cannot resolve", () => {
        // SortableJS may hand back a clone without the data attribute; the drag
        // is real either way, we just don't know the type.
        const drag = setup();

        drag.onDragStart({ item: draggedElement() });

        expect(drag.dragging.value).toBe(true);
        expect(drag.draggedTypeGroup.value).toBeNull();
    });

    it("resolves nothing for a card id that is no longer in the deck", () => {
        const drag = setup();

        drag.onDragStart({ item: draggedElement("card-deleted") });

        expect(drag.draggedTypeGroup.value).toBeNull();
    });

    it("resets on a cancelled drag", () => {
        const drag = setup();
        drag.onDragStart({ item: draggedElement("card-bear") });

        drag.onDragEnd();

        expect(drag.dragging.value).toBe(false);
        expect(drag.draggedTypeGroup.value).toBeNull();
    });
});

describe("useDeckCardDrag — isUnavailable", () => {
    it("dims nothing while no drag is in progress", () => {
        const drag = setup();

        expect(drag.isUnavailable(sectionOf({ key: "land" }))).toBe(false);
    });

    it("dims a default group whose type does not match the dragged card", () => {
        const drag = setup();
        drag.onDragStart({ item: draggedElement("card-bear") });

        expect(drag.isUnavailable(sectionOf({ key: "land" }))).toBe(true);
        expect(drag.isUnavailable(sectionOf({ key: "creature" }))).toBe(false);
    });

    it("never dims a custom category — anything can go in one", () => {
        const drag = setup();
        drag.onDragStart({ item: draggedElement("card-bear") });

        expect(drag.isUnavailable(sectionOf({ key: "cat-ramp", categoryId: "ramp" }))).toBe(false);
    });

    it("never dims the sideboard", () => {
        const drag = setup();
        drag.onDragStart({ item: draggedElement("card-bear") });

        expect(drag.isUnavailable(sectionOf({ key: "side", zone: "side" }))).toBe(false);
    });
});

describe("useDeckCardDrag — groupFor", () => {
    it("clones rather than moving, so the drag library never mutates the deck", () => {
        // The real change comes back from the server; letting SortableJS splice
        // the source array would double-apply it.
        expect(setup().groupFor(sectionOf()).pull).toBe("clone");
    });

    it("lets a custom category accept anything", () => {
        expect(setup().groupFor(sectionOf({ categoryId: "ramp" })).put).toBe(true);
    });

    it("lets the sideboard accept anything", () => {
        expect(setup().groupFor(sectionOf({ zone: "side" })).put).toBe(true);
    });

    it("gates a default group on the dragged card's type at drop time", () => {
        const drag = setup();
        const { put } = drag.groupFor(sectionOf({ key: "creature" }));

        expect(typeof put).toBe("function");
        expect((put as CallableFunction)(null, null, draggedElement("card-bear"))).toBe(true);
        expect((put as CallableFunction)(null, null, draggedElement("card-island"))).toBe(false);
    });

    it("refuses a drop it cannot resolve to a card", () => {
        const { put } = setup().groupFor(sectionOf({ key: "creature" }));

        expect((put as CallableFunction)(null, null, draggedElement())).toBe(false);
    });

    it("puts every section in one SortableJS group so cards can cross between them", () => {
        expect(setup().groupFor(sectionOf()).name).toBe("deck-cards");
        expect(setup().createGroupTarget).toEqual({ name: "deck-cards", pull: false, put: true });
    });
});

describe("useDeckCardDrag — dropping on the create-group target", () => {
    it("takes the dropped card, drains the list and opens the modal", () => {
        const drag = setup();
        drag.onDragStart({ item: draggedElement("card-bear") });
        // SortableJS appends to the bound list before the @add handler runs.
        drag.dropTargetList.value = [bear];

        drag.onDropToCreateGroup();

        // Identity is not preserved — `ref()` hands back a reactive proxy.
        expect(drag.droppedCard.value?.id).toBe(bear.id);
        expect(drag.showCreateGroupModal.value).toBe(true);
        // Drained so SortableJS does not leave a duplicate rendered.
        expect(drag.dropTargetList.value).toEqual([]);
        expect(drag.dragging.value).toBe(false);
        expect(drag.draggedTypeGroup.value).toBeNull();
    });

    it("opens no modal when the drop produced nothing", () => {
        const drag = setup();
        drag.onDragStart({ item: draggedElement("card-bear") });

        drag.onDropToCreateGroup();

        expect(drag.showCreateGroupModal.value).toBe(false);
        expect(drag.droppedCard.value).toBeNull();
        // Drag state still resets, so the UI doesn't stay dimmed.
        expect(drag.dragging.value).toBe(false);
        expect(drag.draggedTypeGroup.value).toBeNull();
    });
});

describe("useDeckCardDrag — dropping on a group", () => {
    it("patches the card's category and zone", () => {
        setup().onDropToGroup({ item: draggedElement("card-bear") }, "category-ramp", "main");

        expect(routerMock.patch).toHaveBeenCalledExactlyOnceWith(
            `/api/decks/${DECK_ID}/cards/card-bear/category`,
            { category_id: "category-ramp", zone: "main" },
            { preserveScroll: true }
        );
    });

    it("sends a null category when dropping back onto a default type group", () => {
        setup().onDropToGroup({ item: draggedElement("card-bear") }, null, "main");

        expect(routerMock.patch).toHaveBeenCalledWith(
            expect.any(String),
            { category_id: null, zone: "main" },
            expect.anything()
        );
    });

    it("sends the side zone when dropping onto the sideboard", () => {
        setup().onDropToGroup({ item: draggedElement("card-bear") }, null, "side");

        expect(routerMock.patch).toHaveBeenCalledWith(
            expect.any(String),
            { category_id: null, zone: "side" },
            expect.anything()
        );
    });

    it("sends nothing when the element carries no card id", () => {
        setup().onDropToGroup({ item: draggedElement() }, null, "main");

        expect(routerMock.patch).not.toHaveBeenCalled();
    });

    it("resets the drag state even when it sends nothing", () => {
        const drag = setup();
        drag.onDragStart({ item: draggedElement("card-bear") });

        drag.onDropToGroup({ item: draggedElement() }, null, "main");

        expect(drag.dragging.value).toBe(false);
        expect(drag.draggedTypeGroup.value).toBeNull();
    });
});
