import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import { setPageProps } from "@/test/inertia.ts";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import DeckCardFreedCopiesModal from "../DeckCardFreedCopiesModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

beforeEach(() => {
    setPageProps({
        containers: [
            { id: "binder", name: "Land Binder" },
            { id: "deckbox", name: "Elves Deckbox" },
            { id: "box", name: "Bulk Box" }
        ]
    });
});

const render = () =>
    mount(DeckCardFreedCopiesModal, {
        props: { name: "Forest", count: 2, deckboxId: "deckbox" },
        attachTo: document.body
    });

const select = (wrapper: ReturnType<typeof render>) => wrapper.findComponent(MonoSelect);
const click = (selector: string): void => document.querySelector<HTMLButtonElement>(selector)!.click();

describe("DeckCardFreedCopiesModal — where the cards go", () => {
    it("starts on unsorted and offers every container but the deckbox they leave", () => {
        const wrapper = render();

        expect(select(wrapper).props("selected")).toBe("unsorted");
        expect(select(wrapper).props("options")).toEqual([
            { value: "unsorted", label: "pages.collection.unsorted" },
            { value: "binder", label: "Land Binder" },
            { value: "box", label: "Bulk Box" }
        ]);
        expect(select(wrapper).props("clearable")).toBe(false);
    });

    it("names the cards and the deckbox they lie in", () => {
        setTestMessages({ de: { pages: { deck: { freed_copies: { question: "{quantity}× {name} aus {container}" } } } } });
        render();

        expect(document.body.textContent).toContain("2× Forest aus Elves Deckbox");
    });

    it("hands back unsorted when left as it opened", () => {
        const wrapper = render();

        click('button[type="submit"]');

        expect(wrapper.emitted("place")).toEqual([["unsorted"]]);
    });

    it("hands back the chosen container", async () => {
        const wrapper = render();
        select(wrapper).vm.$emit("change", "binder");
        await wrapper.vm.$nextTick();

        click('button[type="submit"]');

        expect(wrapper.emitted("place")).toEqual([["binder"]]);
    });

    it("closes without a place when cancelled", () => {
        const wrapper = render();

        click('button[type="button"].btn-default');

        expect(wrapper.emitted("close")).toHaveLength(1);
        expect(wrapper.emitted("place")).toBeUndefined();
    });
});
