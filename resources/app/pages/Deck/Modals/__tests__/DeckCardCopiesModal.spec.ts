import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock, type FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import DeckCardCopiesModal from "../DeckCardCopiesModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const ENDPOINT = "/api/decks/d1/cards/c1/copies";
const RELOAD = { only: ["cards", "commanders", "companion", "deck", "violations"] };
const LANGUAGES = ["en", "de", "ja"];

/** The plan group as the endpoint lists it. */
const plan = (overrides: Record<string, unknown> = {}) => ({
    stack_id: null,
    count: 4,
    splittable: true,
    finish: "nonfoil",
    language: "en",
    proxy: false,
    finishes: ["nonfoil", "foil"],
    ...overrides
});

let http: FetchMock;

beforeEach(() => {
    http = installFetchMock();
    setPageProps({ csrfToken: "token" });
});

const render = async (
    response: unknown = { integration: true, languages: LANGUAGES, claimed: 0, groups: [plan()] }
) => {
    http.json(ENDPOINT, response, 200, "GET");
    const wrapper = mount(DeckCardCopiesModal, {
        props: { deckId: "d1", deckCardId: "c1", name: "Lightning Bolt" },
        attachTo: document.body
    });
    await flushPromises();
    return wrapper;
};

const $ = <T extends Element = HTMLElement>(selector: string): T | null => document.querySelector<T>(selector);
const countInput = () => $<HTMLInputElement>("#deck-card-copies-count");
const submit = async (): Promise<void> => {
    $<HTMLButtonElement>('button[type="submit"]')!.click();
    await flushPromises();
};
const pick = async (id: string): Promise<void> => {
    const input = $<HTMLInputElement>(`#${id}`)!;
    input.checked = true;
    input.dispatchEvent(new Event("change"));
    await flushPromises();
};

describe("DeckCardCopiesModal — loading", () => {
    it("starts on the plan the deck has for the open copies", async () => {
        await render({
            languages: LANGUAGES,
            claimed: 0,
            groups: [plan({ finish: "foil", language: "de", proxy: true })]
        });

        expect($<HTMLInputElement>("#finish-foil")!.checked).toBe(true);
        expect($<HTMLInputElement>("#language-de")!.checked).toBe(true);
        expect($<HTMLInputElement>("#deck-card-copies-proxy")!.checked).toBe(true);
        expect(countInput()!.value).toBe("4");
    });

    it("offers only the finishes the printing exists in", async () => {
        await render({
            integration: true,
            languages: LANGUAGES,
            claimed: 0,
            groups: [plan({ finishes: ["foil", "etched"] })]
        });

        expect($("#finish-nonfoil")).toBeNull();
        expect($("#finish-foil")).not.toBeNull();
        expect($("#finish-etched")).not.toBeNull();
    });

    it("asks for no count when there is one copy to plan", async () => {
        await render({ integration: true, languages: LANGUAGES, claimed: 0, groups: [plan({ count: 1 })] });

        expect(countInput()).toBeNull();
    });

    it("says how many copies are in the collection already", async () => {
        await render({ integration: true, languages: LANGUAGES, claimed: 2, groups: [plan({ count: 2 })] });

        expect(document.body.textContent).toContain("pages.deck.copies.planned");
        expect(document.body.textContent).toContain("pages.deck.copies.claimed");
    });

    it("has nothing to edit when every copy is in the collection", async () => {
        await render({ integration: true, languages: LANGUAGES, claimed: 4, groups: [] });

        expect(document.body.textContent).toContain("pages.deck.copies.all_claimed");
        expect($("#finish-nonfoil")).toBeNull();
        expect($('button[type="submit"]')).toBeNull();
    });

    it("says so when the copies can't be loaded", async () => {
        http.status(ENDPOINT, 500, "GET");
        mount(DeckCardCopiesModal, {
            props: { deckId: "d1", deckCardId: "c1", name: "Lightning Bolt" },
            attachTo: document.body
        });
        await flushPromises();

        expect(document.body.textContent).toContain("pages.deck.copies.load_error");
        expect($('button[type="submit"]')).toBeNull();
    });
});

describe("DeckCardCopiesModal — collection integration off", () => {
    it("says nothing about the collection", async () => {
        await render({ integration: false, languages: LANGUAGES, claimed: 0, groups: [plan()] });

        expect(document.body.textContent).toContain("pages.deck.copies.planned_plain");
        expect(document.body.textContent).not.toMatch(/pages\.deck\.copies\.planned(?!_plain)/u);
        expect(document.body.textContent).not.toContain("pages.deck.copies.claimed");
    });

    it("re-plans a card it may not split as a whole", async () => {
        http.status(ENDPOINT, 204, "PATCH");
        await render({ integration: false, languages: LANGUAGES, claimed: 0, groups: [plan({ splittable: false })] });

        expect(countInput()).toBeNull();
        await submit();
        expect(http.lastCall(ENDPOINT)!.body).toMatchObject({ count: 4 });
    });
});

describe("DeckCardCopiesModal — apply to", () => {
    it("steps between one and the copies on offer", async () => {
        await render({ integration: true, languages: LANGUAGES, claimed: 0, groups: [plan({ count: 2 })] });
        const [add, subtract] = document.querySelectorAll<HTMLButtonElement>(".form-group button[type='button']");

        expect(add.disabled).toBe(true);
        subtract.click();
        await flushPromises();
        expect(countInput()!.value).toBe("1");
        expect(subtract.disabled).toBe(true);
    });

    it("clamps a typed count into range", async () => {
        await render();
        const input = countInput()!;

        input.value = "9";
        input.dispatchEvent(new Event("change"));
        await flushPromises();
        expect(input.value).toBe("4");

        input.value = "0";
        input.dispatchEvent(new Event("change"));
        await flushPromises();
        expect(input.value).toBe("1");
    });
});

describe("DeckCardCopiesModal — saving", () => {
    it("PATCHes the new plan, reloads the rows and the worth, and closes", async () => {
        http.status(ENDPOINT, 204, "PATCH");
        const wrapper = await render();

        await pick("finish-foil");
        await pick("language-ja");
        const input = countInput()!;
        input.value = "2";
        input.dispatchEvent(new Event("change"));
        await submit();

        const call = http.lastCall(ENDPOINT)!;
        expect(call.method).toBe("PATCH");
        expect(call.headers["X-CSRF-TOKEN"]).toBe("token");
        expect(call.body).toEqual({ count: 2, finish: "foil", language: "ja", proxy: false });
        expect(routerMock.reload).toHaveBeenCalledWith(RELOAD);
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("sends the proxy flag as switched", async () => {
        http.status(ENDPOINT, 204, "PATCH");
        await render();

        const toggle = $<HTMLInputElement>("#deck-card-copies-proxy")!;
        toggle.click();
        await submit();

        expect(http.lastCall(ENDPOINT)!.body).toMatchObject({ proxy: true });
    });

    it("shows the server's field errors and stays open", async () => {
        http.json(ENDPOINT, { errors: { finish: ["No foil of this printing."] } }, 422, "PATCH");
        const wrapper = await render();

        await submit();

        expect(document.body.textContent).toContain("No foil of this printing.");
        expect(routerMock.reload).not.toHaveBeenCalled();
        expect(wrapper.emitted("close")).toBeUndefined();
    });

    it("says so when saving fails", async () => {
        http.status(ENDPOINT, 500, "PATCH");
        const wrapper = await render();

        await submit();

        expect(document.body.textContent).toContain("pages.deck.copies.error");
        expect(wrapper.emitted("close")).toBeUndefined();
    });
});
