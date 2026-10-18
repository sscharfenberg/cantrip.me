import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock, type FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import Checkbox from "Components/Form/Checkbox.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import DeckCardUnclaimModal from "../DeckCardUnclaimModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const ENDPOINT = "/api/decks/d1/cards/c1/claims";
const RELOAD = { only: ["cards", "deck", "violations", "tokens", "hasUnclaimedCards"] };

let http: FetchMock;

beforeEach(() => {
    http = installFetchMock();
    setPageProps({
        csrfToken: "token",
        containers: [
            { id: "deckbox", name: "Burn Deckbox" },
            { id: "binder", name: "Trade Binder" }
        ]
    });
});

const render = (containerId: string | null = "deckbox") =>
    mount(DeckCardUnclaimModal, {
        props: {
            deckId: "d1",
            card: {
                id: "c1",
                name: "Lightning Bolt",
                quantity: 4,
                claimed_stacks: [{ id: "s1", container_id: containerId }]
            }
        },
        attachTo: document.body
    });

type Wrapper = ReturnType<typeof render>;
const select = (wrapper: Wrapper) => wrapper.findComponent(MonoSelect);
const submit = async (): Promise<void> => {
    document.querySelector<HTMLButtonElement>('button[type="submit"]')!.click();
    await flushPromises();
};

describe("DeckCardUnclaimModal — where the cards go", () => {
    it("starts on the container the cards lie in, with unsorted and every container on offer", () => {
        const wrapper = render();

        expect(select(wrapper).props("selected")).toBe("deckbox");
        expect(select(wrapper).props("options")).toEqual([
            { value: "unsorted", label: "pages.collection.unsorted" },
            { value: "deckbox", label: "Burn Deckbox" },
            { value: "binder", label: "Trade Binder" }
        ]);
        expect(select(wrapper).props("clearable")).toBe(false);
    });

    it("starts on unsorted for cards in no container", () => {
        expect(select(render(null)).props("selected")).toBe("unsorted");
    });
});

describe("DeckCardUnclaimModal — unclaiming", () => {
    it("left as it opened, only unclaims — then reloads the deck and closes", async () => {
        http.json(ENDPOINT, { removed: false }, 200, "DELETE");
        const wrapper = render();

        await submit();

        expect(http.calls[0]).toMatchObject({ method: "DELETE", body: {} });
        expect(http.calls[0].headers["X-CSRF-TOKEN"]).toBe("token");
        expect(routerMock.reload).toHaveBeenCalledWith(RELOAD);
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("moves the cards to the chosen container", async () => {
        http.json(ENDPOINT, { removed: false }, 200, "DELETE");
        const wrapper = render();
        select(wrapper).vm.$emit("change", "binder");

        await submit();

        expect(http.calls[0].body).toEqual({ move_to: "binder" });
    });

    it("moves the cards to unsorted", async () => {
        http.json(ENDPOINT, { removed: false }, 200, "DELETE");
        const wrapper = render();
        select(wrapper).vm.$emit("change", "unsorted");

        await submit();

        expect(http.calls[0].body).toEqual({ move_to: "unsorted" });
    });

    it("takes the card out of the deck too when asked", async () => {
        http.json(ENDPOINT, { removed: true }, 200, "DELETE");
        const wrapper = render();
        expect(wrapper.findComponent(Checkbox).props("checkedInitially")).toBe(false);
        wrapper.findComponent(Checkbox).vm.$emit("change", true);

        await submit();

        expect(http.calls[0].body).toEqual({ remove_from_deck: true });
    });

    it("says so and stays open when the server refuses", async () => {
        http.status(ENDPOINT, 422, "DELETE");
        const wrapper = render();

        await submit();

        expect(wrapper.findComponent({ name: "FormGroup" }).props("error")).toBe("pages.deck.unclaim.error");
        expect(routerMock.reload).not.toHaveBeenCalled();
        expect(wrapper.emitted("close")).toBeUndefined();
    });

    it("sends one request however often it is submitted", async () => {
        http.hang(ENDPOINT);
        render();

        document.querySelector<HTMLButtonElement>('button[type="submit"]')!.click();
        document.querySelector<HTMLButtonElement>('button[type="submit"]')!.click();
        await flushPromises();

        expect(http.calls).toHaveLength(1);
    });
});
