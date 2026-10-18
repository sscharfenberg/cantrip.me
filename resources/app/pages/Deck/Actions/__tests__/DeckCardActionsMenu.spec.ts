import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { makeDeckCard } from "@/test/factories/deckCard.ts";
import { setPageProps } from "@/test/inertia.ts";
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
        global: { stubs: { DeckCardUnclaimModal: true } }
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
