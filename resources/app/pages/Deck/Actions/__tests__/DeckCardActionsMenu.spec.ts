import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { makeDeckCard } from "@/test/factories/deckCard.ts";
import { installFetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import type { DeckCardRow } from "Types/deckPage.ts";
import DeckCardActionsMenu from "../DeckCardActionsMenu.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

beforeEach(() => setPageProps({ csrfToken: "token" }));

const render = (card: DeckCardRow) =>
    mount(DeckCardActionsMenu, {
        props: {
            deckId: "d1",
            card,
            cards: [card],
            categories: [],
            categoryNameMax: 32,
            maxCopies: 4,
            isSingleton: false,
            hasSideboard: true,
            heroCardId: null,
            collectionMode: "C"
        },
        global: {
            stubs: {
                DeckCardUnclaimModal: true,
                DeckCardCopiesModal: true,
                DeckCardAssignStackModal: true,
                DeckCardFreedCopiesModal: true
            }
        }
    });

const unclaimEntry = (wrapper: ReturnType<typeof render>) =>
    wrapper.findAll(".popover-list-item").find(item => item.text().includes("pages.deck.unclaim.link"));

describe("DeckCardActionsMenu — unclaim", () => {
    it("is offered for a card stacks are claimed for", () => {
        const card = makeDeckCard({ claimed_stacks: [{ id: "s1", container_id: "deckbox" }] });

        expect(unclaimEntry(render(card))).toBeDefined();
    });

    it("is not offered for a card nothing is claimed for", () => {
        expect(unclaimEntry(render(makeDeckCard()))).toBeUndefined();
    });

    it("opens the unclaim dialog for the card", async () => {
        const card = makeDeckCard({ claimed_stacks: [{ id: "s1", container_id: "deckbox" }] });
        const wrapper = render(card);

        await unclaimEntry(wrapper)!.trigger("click");

        const modal = wrapper.findComponent({ name: "DeckCardUnclaimModal" });
        expect(modal.props("deckId")).toBe("d1");
        expect(modal.props("card")).toEqual(card);
    });
});

describe("DeckCardActionsMenu — where freed copies go", () => {
    const QUANTITY_URL = "/api/decks/d1/cards/c1/quantity";

    it("asks when removing frees copies in the deckbox, and sends the answer along", async () => {
        vi.useFakeTimers();
        const http = installFetchMock();
        http.on(QUANTITY_URL, call =>
            (call.body as { move_to?: string }).move_to === undefined
                ? Response.json({ reason: "freed_copies_need_a_place", count: 1, container_id: "deckbox" }, { status: 409 })
                : Response.json({ quantity: 3 })
        );
        const card = makeDeckCard({ id: "c1", name: "Forest", quantity: 4 });
        setPageProps({ csrfToken: "token", cards: [card] });
        const wrapper = render(card);

        await wrapper.find('[aria-label="pages.deck.card_quantity.decrement"]').trigger("click");
        await vi.advanceTimersByTimeAsync(500);

        const modal = wrapper.findComponent({ name: "DeckCardFreedCopiesModal" });
        expect(modal.props()).toEqual({ name: "Forest", count: 1, deckboxId: "deckbox" });

        modal.vm.$emit("place", "binder");
        await flushPromises();

        expect(http.lastCall(QUANTITY_URL)?.body).toEqual({ delta: -1, move_to: "binder" });
        expect(wrapper.findComponent({ name: "DeckCardFreedCopiesModal" }).exists()).toBe(false);
        vi.useRealTimers();
    });
});

describe("DeckCardActionsMenu — edit copies", () => {
    it("opens the copies dialog for the card, whatever its collection state", async () => {
        const card = makeDeckCard({ name: "Lightning Bolt" });
        const wrapper = render(card);

        await wrapper
            .findAll(".popover-list-item")
            .find(item => item.text().includes("pages.deck.copies.link"))!
            .trigger("click");

        const modal = wrapper.findComponent({ name: "DeckCardCopiesModal" });
        expect(modal.props()).toEqual({ deckId: "d1", deckCardId: card.id, name: "Lightning Bolt" });
    });
});

describe("DeckCardActionsMenu — assign physical copy", () => {
    it("reloads the row, the open-cards flag and the deck's worth after a stack is assigned", async () => {
        const http = installFetchMock();
        http.status("/api/decks/d1/cards/c1/assigned-stacks", 204, "PATCH");
        const wrapper = render(makeDeckCard({ id: "c1" }));

        await wrapper
            .findAll(".popover-list-item")
            .find(item => item.text().includes("pages.deck.assign_stack.link"))!
            .trigger("click");
        wrapper.findComponent({ name: "DeckCardAssignStackModal" }).vm.$emit("select", "s1");
        await flushPromises();

        expect(http.lastCall("/api/decks/d1/cards/c1/assigned-stacks")!.body).toEqual({ card_stack_id: "s1" });
        expect(routerMock.reload).toHaveBeenCalledWith({ only: ["cards", "deck", "hasUnclaimedCards"] });
    });
});
