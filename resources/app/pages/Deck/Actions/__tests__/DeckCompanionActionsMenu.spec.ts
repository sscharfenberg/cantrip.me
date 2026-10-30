import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import DeckCompanionActionsMenu from "../DeckCompanionActionsMenu.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const DECK_ID = "deck-1";
const PRINTINGS_URL = `/api/decks/${DECK_ID}/companion/printings`;
const PRINTING_URL = `/api/decks/${DECK_ID}/companion/printing`;

const printing = (id: string, isCurrent: boolean) => ({
    id,
    name: "Lurrus of the Dream-Den",
    card_image_0: null,
    card_image_1: null,
    artist: null,
    cn: id,
    finishes: ["nonfoil"],
    set: { name: "Ikoria: Lair of Behemoths", code: "iko", path: null },
    in_collection: false,
    is_current: isCurrent
});

let http: FetchMock;

beforeEach(() => {
    setPageProps({ csrfToken: "csrf-token" });
    http = installFetchMock();
    http.json(PRINTINGS_URL, [printing("p-current", true), printing("p-new", false)]);
});

const render = () =>
    mount(DeckCompanionActionsMenu, {
        props: {
            deckId: DECK_ID,
            deckCardId: "deck-card-1",
            companionName: "Lurrus of the Dream-Den",
            defaultCardId: "p-current",
            heroCardId: null,
            collectionMode: "B"
        },
        attachTo: document.body
    });

/**
 * Open the switch-printing modal from the menu and pick the printing that is
 * not current. The modal teleports into `<body>`, so the list is found through
 * `document`, not through the wrapper.
 */
const pickNewPrinting = async (wrapper: ReturnType<typeof render>): Promise<void> => {
    const entry = wrapper.findAll(".popover-list-item").find(n => n.text().includes("switch_printing.link"));
    if (!entry) throw new Error("no switch-printing entry in the companion menu");
    await entry.trigger("click");
    await flushPromises();

    const option = document.querySelector<HTMLButtonElement>(
        ".switch-printing__list button:not(.switch-printing__list-item--current)"
    );
    if (!option) throw new Error("the switch-printing modal listed no other printing");
    option.click();
    await flushPromises();
};

describe("DeckCompanionActionsMenu — switch printing", () => {
    it("PATCHes the chosen printing", async () => {
        await pickNewPrinting(render());

        expect(http.lastCall(PRINTING_URL)).toMatchObject({ method: "PATCH", body: { default_card_id: "p-new" } });
    });

    it("reloads every prop the server derives from the printing", async () => {
        await pickNewPrinting(render());

        /*
         * `deck` carries the header's total worth and the hero image;
         * `hasUnclaimedCards` follows the printing in mode B.
         */
        expect(routerMock.reload).toHaveBeenCalledExactlyOnceWith({
            only: ["deck", "companion", "tokens", "hasUnclaimedCards"]
        });
    });

    it("does not reload when the server refuses", async () => {
        http.status(PRINTING_URL, 422);

        await pickNewPrinting(render());

        expect(routerMock.reload).not.toHaveBeenCalled();
    });
});

describe("DeckCompanionActionsMenu — edit copies", () => {
    it("opens the copies dialog for the card's deck card", async () => {
        http.json(
            `/api/decks/${DECK_ID}/cards/deck-card-1/copies`,
            { languages: ["en"], claimed: 0, groups: [] },
            200,
            "GET"
        );
        const wrapper = render();

        await wrapper
            .findAll(".popover-list-item")
            .find(item => item.text().includes("pages.deck.copies.link"))!
            .trigger("click");
        await flushPromises();

        const modal = wrapper.findComponent({ name: "DeckCardCopiesModal" });
        expect(modal.props()).toEqual({ deckId: DECK_ID, deckCardId: "deck-card-1", name: "Lurrus of the Dream-Den" });
        expect(http.callsTo(`/api/decks/${DECK_ID}/cards/deck-card-1/copies`)).toHaveLength(1);
    });
});

describe("DeckCompanionActionsMenu — assign physical copy", () => {
    it("reloads the card, the open-cards flag and the deck's worth after a stack is assigned", async () => {
        http.json(`/api/decks/${DECK_ID}/cards/deck-card-1/assignable-stacks`, [], 200, "GET");
        http.status(`/api/decks/${DECK_ID}/cards/deck-card-1/assigned-stacks`, 204, "PATCH");
        const wrapper = render();
        // The entry is offered in mode C only.
        await wrapper.setProps({ collectionMode: "C" });

        await wrapper
            .findAll(".popover-list-item")
            .find(item => item.text().includes("pages.deck.assign_stack.link"))!
            .trigger("click");
        await flushPromises();
        wrapper.findComponent({ name: "DeckCardAssignStackModal" }).vm.$emit("select", "s1");
        await flushPromises();

        expect(routerMock.reload).toHaveBeenCalledWith({ only: ["companion", "deck", "hasUnclaimedCards"] });
    });
});

describe("DeckCompanionActionsMenu — removing the companion", () => {
    const COMPANION_URL = `/api/decks/${DECK_ID}/companion`;
    const RELOAD = ["deck", "companion", "cards", "violations", "tokens", "hasUnclaimedCards"];
    const FREED = { reason: "freed_copies_need_a_place", count: 1, container_id: "deckbox", name: "Lurrus" };
    const remove = async (wrapper: ReturnType<typeof render>): Promise<void> => {
        const entry = wrapper.findAll(".popover-list-item").find(n => n.text().includes("companion.remove"));
        if (!entry) throw new Error("no remove entry in the companion menu");
        await entry.trigger("click");
        await flushPromises();
    };
    /** 409 until the request names a place, then the removal goes through. */
    const askUntilPlaced = () =>
        http.on(
            COMPANION_URL,
            call =>
                (call.body as { move_to?: string } | undefined)?.move_to === undefined
                    ? Response.json(FREED, { status: 409 })
                    : Response.json({ companion_oracle_card_id: null }),
            "DELETE"
        );
    const question = (wrapper: ReturnType<typeof render>) => wrapper.findComponent({ name: "DeckCardFreedCopiesModal" });

    it("removes it without a body when nothing needs a place", async () => {
        http.json(COMPANION_URL, { companion_oracle_card_id: null }, 200, "DELETE");
        const wrapper = render();

        await remove(wrapper);

        expect(http.callsTo(COMPANION_URL).map(call => call.body)).toEqual([undefined]);
        expect(question(wrapper).exists()).toBe(false);
        expect(routerMock.reload).toHaveBeenCalledWith(expect.objectContaining({ only: RELOAD }));
    });

    it("asks where its claimed copies go, then removes it with the answer", async () => {
        askUntilPlaced();
        const wrapper = render();
        await remove(wrapper);

        expect(question(wrapper).props()).toEqual({ name: "Lurrus of the Dream-Den", count: 1, deckboxId: "deckbox" });
        question(wrapper).vm.$emit("place", "binder");
        await flushPromises();

        expect(http.callsTo(COMPANION_URL).map(call => call.body)).toEqual([undefined, { move_to: "binder" }]);
        expect(routerMock.reload).toHaveBeenCalledWith(expect.objectContaining({ only: RELOAD }));
    });

    it("keeps the companion when the question is cancelled", async () => {
        askUntilPlaced();
        const wrapper = render();
        await remove(wrapper);

        question(wrapper).vm.$emit("close");
        await flushPromises();

        expect(http.callsTo(COMPANION_URL)).toHaveLength(1);
        expect(question(wrapper).exists()).toBe(false);
        expect(routerMock.reload).not.toHaveBeenCalled();
    });
});
