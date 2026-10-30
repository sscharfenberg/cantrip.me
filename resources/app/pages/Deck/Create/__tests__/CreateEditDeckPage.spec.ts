import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { formMocks, setPageProps } from "@/test/inertia.ts";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import CreateEditDeckPage from "../CreateEditDeckPage.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const existingDeck = {
    id: "deck-1",
    name: "Krenko",
    description: null,
    format: "legacy",
    visibility: "private" as const,
    container_id: "deckbox",
    bracket: null,
    suggestedBracket: null,
    commander: null,
    companion: null,
    signatureSpell: null,
    cards: [],
    heroCard: null
};

beforeEach(() => {
    setPageProps({ csrfToken: "csrf-token" });
});

const render = () =>
    mount(CreateEditDeckPage, {
        props: {
            mode: "edit",
            formats: ["legacy"],
            capabilities: {},
            nameMax: 100,
            descriptionMax: 1000,
            existingDeck,
            containers: [
                { id: "deckbox", name: "Goblin Box", type: "deckbox", is_deckbox: true },
                { id: "binder", name: "Red Binder", type: "binder", is_deckbox: false }
            ]
        },
        global: { stubs: { CommandZoneField: true, DeckHeroImagePicker: true, DeckFormatCapabilities: true } }
    });

/** The `move_to` picker: the only select offering "unsorted". */
const moveToSelect = (wrapper: ReturnType<typeof render>) =>
    wrapper
        .findAllComponents(MonoSelect)
        .find(select => (select.props("options") as { value: string }[]).some(o => o.value === "unsorted"));

describe("CreateEditDeckPage — where the claimed copies of a card leaving the command zone go", () => {
    it("asks nothing until the server does", () => {
        const wrapper = render();

        expect(moveToSelect(wrapper)).toBeUndefined();
        expect(wrapper.find('input[name="move_to"]').exists()).toBe(false);
    });

    it("asks with the server's question, Unsorted preselected, the deckbox left out", async () => {
        const wrapper = render();
        formMocks[formMocks.length - 1].errors.move_to = "1× Krenko will leave the deck.";
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain("1× Krenko will leave the deck.");
        expect(moveToSelect(wrapper)!.props("options")).toEqual([
            { value: "unsorted", label: "pages.collection.unsorted" },
            { value: "binder", label: "Red Binder" }
        ]);
        expect((wrapper.find('input[name="move_to"]').element as HTMLInputElement).value).toBe("unsorted");
    });

    it("sends the container picked", async () => {
        const wrapper = render();
        formMocks[formMocks.length - 1].errors.move_to = "1× Krenko will leave the deck.";
        await wrapper.vm.$nextTick();

        moveToSelect(wrapper)!.vm.$emit("change", "binder");
        await wrapper.vm.$nextTick();

        expect((wrapper.find('input[name="move_to"]').element as HTMLInputElement).value).toBe("binder");
    });
});
