import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import { routerMock } from "@/test/inertia.ts";
import Checkbox from "Components/Form/Checkbox.vue";
import MonoSelect from "Components/Form/Select/MonoSelect.vue";
import UnclaimCardStackModal from "../UnclaimCardStackModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const SEARCH = "/collection?search=sol+ring";

const CONTAINERS = [
    { id: "c1", name: "Krenko Deckbox" },
    { id: "c2", name: "Trade Binder" },
    { id: "c3", name: "Bulk Box" }
];

const CLAIMS = [
    { deck_id: "d1", deck_name: "Krenko", is_commander: false },
    { deck_id: "d2", deck_name: "Edgar", is_commander: false }
];

const render = (
    current: { id: string | null; name: string | null } = { id: "c1", name: "Krenko Deckbox" },
    containers = CONTAINERS,
    claims = CLAIMS
) => {
    history.replaceState(null, "", SEARCH);
    return mount(UnclaimCardStackModal, {
        props: {
            cardStack: {
                id: "s1",
                name: "Sol Ring",
                amount: 1,
                set_code: "lea",
                collector_number: "269",
                claims
            },
            containers,
            currentContainerId: current.id,
            currentContainerName: current.name
        },
        attachTo: document.body
    });
};

type Wrapper = ReturnType<typeof render>;
const select = (wrapper: Wrapper) => wrapper.findComponent(MonoSelect);
const submit = (): void => document.querySelector<HTMLButtonElement>('button[type="submit"]')!.click();
const deleteCall = () => {
    const [target, options] = routerMock.delete.mock.calls[0] as [string, { data: unknown }];
    return { url: new URL(target, "http://x"), data: options.data };
};

afterEach(() => history.replaceState(null, "", "/"));

describe("UnclaimCardStackModal — where the card can go", () => {
    it("offers unsorted first, then every container but the one it is in", () => {
        expect(select(render()).props("options")).toEqual([
            { value: "unsorted", label: "pages.collection.unsorted" },
            { value: "c2", label: "Trade Binder" },
            { value: "c3", label: "Bulk Box" }
        ]);
        expect(select(render()).props("sort")).toBe(false);
    });

    it("does not offer unsorted for a card that is already unsorted", () => {
        const options = select(render({ id: null, name: null })).props("options") as { value: string }[];

        expect(options.map(option => option.value)).toEqual(["c1", "c2", "c3"]);
    });

    it("only asks about the unclaim when there is nowhere else to go", () => {
        const wrapper = render({ id: null, name: null }, []);

        expect(select(wrapper).exists()).toBe(false);
        expect(document.body.textContent).not.toContain("pages.collection.unclaim.move_hint");
    });

    it("says that no choice keeps the card where it is", () => {
        setTestMessages({
            de: {
                pages: {
                    collection: { unclaim: { keep: "In {container} lassen", keep_unsorted: "Unsortiert lassen" } }
                }
            }
        });

        expect(select(render()).props("placeholder")).toBe("In Krenko Deckbox lassen");
        expect(select(render({ id: null, name: null })).props("placeholder")).toBe("Unsortiert lassen");
    });

    it("names the card and every deck reserving it", () => {
        setTestMessages({ de: { pages: { collection: { unclaim: { question: "{description} — {decks}" } } } } });
        render();

        expect(document.body.textContent).toContain("1× Sol Ring (LEA #269) — Krenko, Edgar");
    });
});

describe("UnclaimCardStackModal — unclaiming", () => {
    it("without a choice, only unclaims — and comes back to this page", () => {
        render();

        submit();

        const { url, data } = deleteCall();
        expect(url.pathname).toBe("/collection/cardstack/s1/claims");
        expect(url.searchParams.get("return")).toBe(SEARCH);
        expect(data).toEqual({});
    });

    it("moves the card to the chosen container", async () => {
        const wrapper = render();
        select(wrapper).vm.$emit("change", "c2");
        await flushPromises();

        submit();

        expect(deleteCall().data).toEqual({ move_to: "c2" });
    });

    it("moves the card to unsorted", async () => {
        const wrapper = render();
        select(wrapper).vm.$emit("change", "unsorted");
        await flushPromises();

        submit();

        expect(deleteCall().data).toEqual({ move_to: "unsorted" });
    });

    it("forgets a choice that was cleared again", async () => {
        const wrapper = render();
        select(wrapper).vm.$emit("change", "c2");
        select(wrapper).vm.$emit("change", "");
        await flushPromises();

        submit();

        expect(deleteCall().data).toEqual({});
    });

    it("shows the server's objection to the chosen container", async () => {
        const wrapper = render();
        submit();

        (routerMock.delete.mock.calls[0][1] as { onError: (e: Record<string, string>) => void }).onError({
            move_to: "The selected container is invalid."
        });
        await flushPromises();

        expect(wrapper.findComponent({ name: "FormGroup" }).props("error")).toBe("The selected container is invalid.");
    });

    it("leaves the deck list alone unless asked", () => {
        const wrapper = render();

        expect(wrapper.findComponent(Checkbox).props("checkedInitially")).toBe(false);

        submit();

        expect(deleteCall().data).toEqual({});
    });

    it("takes the cards out of the deck list too when asked, alongside a move", async () => {
        const wrapper = render();
        select(wrapper).vm.$emit("change", "c2");
        wrapper.findComponent(Checkbox).vm.$emit("change", true);
        await flushPromises();

        submit();

        expect(deleteCall().data).toEqual({ move_to: "c2", remove_from_decks: true });
    });

    it("names the decks the cards would leave", () => {
        setTestMessages({
            de: { pages: { collection: { unclaim: { remove_from_decks: "Auch aus {decks} entfernen" } } } }
        });

        expect(render().findComponent(Checkbox).props("label")).toBe("Auch aus Krenko, Edgar entfernen");
    });

    it("warns, naming its decks, when the card is a deck's commander", () => {
        setTestMessages({
            de: { pages: { collection: { unclaim: { commander_warning: "{name} führt {decks} an" } } } }
        });
        render(undefined, CONTAINERS, [
            { deck_id: "d1", deck_name: "Krenko", is_commander: true },
            { deck_id: "d2", deck_name: "Edgar", is_commander: false }
        ]);

        const warning = document.querySelector(".form-legend li.warning");
        expect(warning?.textContent?.trim()).toBe("Sol Ring führt Krenko an");
    });

    it("does not warn when the card is no deck's commander", () => {
        render();

        expect(document.querySelector(".form-legend li.warning")).toBeNull();
    });

    it("sends one request however often it is submitted", () => {
        render();

        submit();
        submit();

        expect(routerMock.delete).toHaveBeenCalledOnce();
    });

    it("closes once the server is done", () => {
        const wrapper = render();
        submit();

        (routerMock.delete.mock.calls[0][1] as { onSuccess: () => void }).onSuccess();

        expect(wrapper.emitted("close")).toHaveLength(1);
    });
});
