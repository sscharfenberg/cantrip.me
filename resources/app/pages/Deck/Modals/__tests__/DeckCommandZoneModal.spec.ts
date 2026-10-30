import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import CommanderCommandZonePickerModal from "@/pages/Deck/Create/CommanderCommandZonePickerModal.vue";
import OathbreakerCommandZonePickerModal from "@/pages/Deck/Create/OathbreakerCommandZonePickerModal.vue";
import { installFetchMock, type FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import DeckCardFreedCopiesModal from "../DeckCardFreedCopiesModal.vue";
import DeckCommandZoneModal from "../DeckCommandZoneModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const ENDPOINT = "/api/decks/d1/commander";
const RELOAD = { only: ["deck", "commanders", "cards", "violations", "tokens", "hasUnclaimedCards"] };

const card = (id: string) => ({ id, name: id }) as never;

let http: FetchMock;

beforeEach(() => {
    http = installFetchMock();
    setPageProps({ csrfToken: "token" });
});

const render = (format: string) =>
    mount(DeckCommandZoneModal, {
        props: { deckId: "d1", format },
        global: {
            stubs: {
                CommanderCommandZonePickerModal: true,
                OathbreakerCommandZonePickerModal: true,
                DeckCardFreedCopiesModal: true
            }
        }
    });

describe("DeckCommandZoneModal", () => {
    it("opens the format's own picker", () => {
        expect(render("commander").findComponent(CommanderCommandZonePickerModal).exists()).toBe(true);
        expect(render("oathbreaker").findComponent(OathbreakerCommandZonePickerModal).exists()).toBe(true);
        expect(render("oathbreaker").findComponent(CommanderCommandZonePickerModal).exists()).toBe(false);
    });

    it("saves a commander and partner as the new command zone, then reloads what depends on it", async () => {
        http.status(ENDPOINT, 204, "PATCH");
        const wrapper = render("commander");

        wrapper.findComponent(CommanderCommandZonePickerModal).vm.$emit("confirm", card("krenko"), card("partner"));
        await flushPromises();

        expect(http.calls[0]).toMatchObject({
            method: "PATCH",
            body: { commander_id: "krenko", companion_id: "partner" }
        });
        expect(routerMock.reload).toHaveBeenCalledWith(RELOAD);
    });

    it("sends an Oathbreaker's second card as its signature spell", async () => {
        http.status(ENDPOINT, 204, "PATCH");
        const wrapper = render("oathbreaker");

        wrapper.findComponent(OathbreakerCommandZonePickerModal).vm.$emit("confirm", card("chandra"), card("bolt"));
        await flushPromises();

        expect(http.calls[0].body).toEqual({ commander_id: "chandra", signature_spell_id: "bolt" });
    });

    it("sends a lone commander without a second card", async () => {
        http.status(ENDPOINT, 204, "PATCH");
        const wrapper = render("commander");

        wrapper.findComponent(CommanderCommandZonePickerModal).vm.$emit("confirm", card("krenko"), null);
        await flushPromises();

        expect(http.calls[0].body).toEqual({ commander_id: "krenko" });
    });

    it("reloads nothing when the server refuses", async () => {
        http.status(ENDPOINT, 422, "PATCH");
        const wrapper = render("commander");

        wrapper.findComponent(CommanderCommandZonePickerModal).vm.$emit("confirm", card("krenko"), null);
        await flushPromises();

        expect(routerMock.reload).not.toHaveBeenCalled();
    });

    it("closes with its picker", () => {
        const wrapper = render("commander");

        wrapper.findComponent(CommanderCommandZonePickerModal).vm.$emit("close");

        expect(wrapper.emitted("close")).toHaveLength(1);
    });
});

describe("DeckCommandZoneModal — copies of a card that leaves", () => {
    const FREED = { reason: "freed_copies_need_a_place", count: 1, container_id: "deckbox", name: "Krenko" };
    /** 409 until the request names a place, then 204. */
    const askUntilPlaced = () =>
        http.on(ENDPOINT, call =>
            (call.body as { move_to?: string }).move_to === undefined
                ? Response.json(FREED, { status: 409 })
                : new Response(null, { status: 204 })
        );
    const confirm = async (wrapper: ReturnType<typeof render>) => {
        const picker = wrapper.findComponent(CommanderCommandZonePickerModal);
        picker.vm.$emit("confirm", card("tymna"), null);
        picker.vm.$emit("close");
        await flushPromises();
    };

    it("stays open after the picker closes, to ask where the cards go", async () => {
        askUntilPlaced();
        const wrapper = render("commander");

        await confirm(wrapper);

        expect(wrapper.emitted("close")).toBeUndefined();
        expect(wrapper.findComponent(CommanderCommandZonePickerModal).exists()).toBe(false);
        expect(wrapper.findComponent(DeckCardFreedCopiesModal).props()).toEqual({
            name: "Krenko",
            count: 1,
            deckboxId: "deckbox"
        });
    });

    it("sends the change again with the place, then reloads and closes", async () => {
        askUntilPlaced();
        const wrapper = render("commander");
        await confirm(wrapper);

        wrapper.findComponent(DeckCardFreedCopiesModal).vm.$emit("place", "binder");
        await flushPromises();

        expect(http.calls.map(call => call.body)).toEqual([
            { commander_id: "tymna" },
            { commander_id: "tymna", move_to: "binder" }
        ]);
        expect(routerMock.reload).toHaveBeenCalledWith(RELOAD);
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("changes nothing when the question is cancelled", async () => {
        askUntilPlaced();
        const wrapper = render("commander");
        await confirm(wrapper);

        wrapper.findComponent(DeckCardFreedCopiesModal).vm.$emit("close");
        await flushPromises();

        expect(http.calls).toHaveLength(1);
        expect(routerMock.reload).not.toHaveBeenCalled();
        expect(wrapper.emitted("close")).toHaveLength(1);
    });
});
