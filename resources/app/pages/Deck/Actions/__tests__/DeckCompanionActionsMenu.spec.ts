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
