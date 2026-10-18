import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import CommanderCommandZonePickerModal from "@/pages/Deck/Create/CommanderCommandZonePickerModal.vue";
import OathbreakerCommandZonePickerModal from "@/pages/Deck/Create/OathbreakerCommandZonePickerModal.vue";
import { installFetchMock, type FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
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
        global: { stubs: { CommanderCommandZonePickerModal: true, OathbreakerCommandZonePickerModal: true } }
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
