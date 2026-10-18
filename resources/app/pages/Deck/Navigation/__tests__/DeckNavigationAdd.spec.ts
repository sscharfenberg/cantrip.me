import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import type { DeckMeta } from "Types/deckPage.ts";
import DeckCommandZoneModal from "../../Modals/DeckCommandZoneModal.vue";
import DeckNavigationAdd from "../DeckNavigationAdd.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const deck = (overrides: Partial<DeckMeta> = {}): DeckMeta =>
    ({ id: "d1", format: "commander", requires_commander: true, allows_companion: false, ...overrides }) as DeckMeta;

const render = (meta: DeckMeta) =>
    mount(DeckNavigationAdd, {
        props: { deck: meta, cards: [], companion: null, companionRoster: [], commanderColorIdentity: "" },
        global: {
            stubs: {
                DeckNavigationQuickAdd: true,
                CardAddModal: true,
                AddCompanionModal: true,
                DeckCommandZoneModal: true
            }
        }
    });

const commandZoneButton = (wrapper: ReturnType<typeof render>) =>
    wrapper.findAll("button").find(button => button.text().includes("pages.deck.command_zone.label"));

describe("DeckNavigationAdd — the command zone", () => {
    it("opens the command zone picker for the deck", async () => {
        const wrapper = render(deck({ format: "oathbreaker" }));

        await commandZoneButton(wrapper)!.trigger("click");

        const modal = wrapper.findComponent(DeckCommandZoneModal);
        expect(modal.props()).toEqual({ deckId: "d1", format: "oathbreaker" });
    });

    it("has no command zone button for a format without one", () => {
        expect(commandZoneButton(render(deck({ format: "legacy", requires_commander: false })))).toBeUndefined();
    });

    it("closes the picker again", async () => {
        const wrapper = render(deck());
        await commandZoneButton(wrapper)!.trigger("click");

        wrapper.findComponent(DeckCommandZoneModal).vm.$emit("close");
        await wrapper.vm.$nextTick();

        expect(wrapper.findComponent(DeckCommandZoneModal).exists()).toBe(false);
    });
});
