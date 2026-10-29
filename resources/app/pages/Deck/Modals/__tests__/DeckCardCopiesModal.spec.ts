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
    condition: null,
    finishes: ["nonfoil", "foil"],
    container: null,
    other_decks: [],
    ...overrides
});

/** A claimed stack's group as the endpoint lists it. */
const stack = (overrides: Record<string, unknown> = {}) => ({
    stack_id: "s1",
    count: 2,
    splittable: true,
    finish: "foil",
    language: "de",
    proxy: true,
    condition: "played",
    finishes: ["nonfoil", "foil", "etched"],
    container: { id: "b1", name: "Binder" },
    other_decks: [],
    ...overrides
});

const CONDITIONS = ["mint", "near_mint", "played"];

let http: FetchMock;

beforeEach(() => {
    http = installFetchMock();
    setPageProps({ csrfToken: "token" });
});

const render = async (
    response: unknown = {
        integration: true,
        languages: LANGUAGES,
        conditions: CONDITIONS,
        claimed: 0,
        groups: [plan()]
    }
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
        await render({
            integration: true,
            languages: LANGUAGES,
            conditions: CONDITIONS,
            claimed: 0,
            groups: [plan({ count: 1 })]
        });

        expect(countInput()).toBeNull();
    });

    it("says how many copies are in the collection already", async () => {
        await render({
            integration: true,
            languages: LANGUAGES,
            conditions: CONDITIONS,
            claimed: 2,
            groups: [plan({ count: 2 })]
        });

        expect(document.body.textContent).toContain("pages.deck.copies.planned");
        expect(document.body.textContent).toContain("pages.deck.copies.claimed");
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
        await render({
            integration: false,
            languages: LANGUAGES,
            conditions: CONDITIONS,
            claimed: 0,
            groups: [plan()]
        });

        expect(document.body.textContent).toContain("pages.deck.copies.planned_plain");
        expect(document.body.textContent).not.toMatch(/pages\.deck\.copies\.planned(?!_plain)/u);
        expect(document.body.textContent).not.toContain("pages.deck.copies.claimed");
    });

    it("re-plans a card it may not split as a whole", async () => {
        http.status(ENDPOINT, 204, "PATCH");
        await render({
            integration: false,
            languages: LANGUAGES,
            conditions: CONDITIONS,
            claimed: 0,
            groups: [plan({ splittable: false })]
        });

        expect(countInput()).toBeNull();
        await submit();
        expect(http.lastCall(ENDPOINT)!.body).toMatchObject({ count: 4 });
    });
});

describe("DeckCardCopiesModal — copies in the collection", () => {
    const both = {
        integration: true,
        languages: LANGUAGES,
        conditions: CONDITIONS,
        claimed: 2,
        groups: [stack(), plan({ count: 2 })]
    };
    const groupButtons = () => [...document.querySelectorAll<HTMLButtonElement>(".deck-copies-modal__group")];

    it("lists every group and opens on the plan", async () => {
        await render(both);

        expect(groupButtons().map(button => button.textContent!.trim())).toEqual([
            "2× enums.finishes.foil · enums.card_languages.de · form.fields.proxy.label · enums.conditions.played — Binder",
            "2× enums.finishes.nonfoil · enums.card_languages.en — pages.deck.copies.where_planned"
        ]);
        expect(groupButtons()[1].getAttribute("aria-checked")).toBe("true");
        expect($(".form-select")).toBeNull();
        expect(document.body.textContent).not.toContain("pages.deck.copies.stack_note");
    });

    it("starts a stack's form on the stack's values, with its condition and a note it is the collection's", async () => {
        await render(both);

        groupButtons()[0].click();
        await flushPromises();

        expect(groupButtons()[0].getAttribute("aria-checked")).toBe("true");
        expect($<HTMLInputElement>("#finish-foil")!.checked).toBe(true);
        expect($<HTMLInputElement>("#finish-etched")).not.toBeNull();
        expect($<HTMLInputElement>("#language-de")!.checked).toBe(true);
        expect($<HTMLInputElement>("#deck-card-copies-proxy")!.checked).toBe(true);
        expect(document.body.textContent).toContain("pages.deck.copies.stack_note");
        expect(document.body.textContent).toContain("pages.deck.copies.count_hint_stack");
        expect($(".form-select")).not.toBeNull();
        expect($(".form-select__button")!.textContent).toContain("enums.conditions.played");
    });

    it("names the other decks a stack belongs to", async () => {
        await render({ ...both, groups: [stack({ other_decks: ["Burn", "Storm"] }), plan()] });

        groupButtons()[0].click();
        await flushPromises();

        expect(document.body.textContent).toContain("pages.deck.copies.other_decks");
    });

    it("opens a lone stack directly, without a list", async () => {
        await render({ ...both, claimed: 2, groups: [stack()] });

        expect(groupButtons()).toHaveLength(0);
        expect(document.body.textContent).toContain("pages.deck.copies.claimed_here");
        expect(document.body.textContent).toContain("pages.deck.copies.stack_note");
        expect($('button[type="submit"]')).not.toBeNull();
    });

    it("PATCHes a stack edit with the stack and its condition", async () => {
        http.status(ENDPOINT, 204, "PATCH");
        const wrapper = await render(both);
        groupButtons()[0].click();
        await flushPromises();

        await pick("language-ja");
        const input = countInput()!;
        input.value = "1";
        input.dispatchEvent(new Event("change"));
        await submit();

        expect(http.lastCall(ENDPOINT)!.body).toEqual({
            stack_id: "s1",
            condition: "played",
            count: 1,
            finish: "foil",
            language: "ja",
            proxy: true
        });
        expect(routerMock.reload).toHaveBeenCalledWith(RELOAD);
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("sends the condition picked", async () => {
        http.status(ENDPOINT, 204, "PATCH");
        const wrapper = await render(both);
        groupButtons()[0].click();
        await flushPromises();

        wrapper.findComponent({ name: "MonoSelect" }).vm.$emit("change", "near_mint");
        await submit();

        expect(http.lastCall(ENDPOINT)!.body).toMatchObject({ stack_id: "s1", condition: "near_mint" });
    });

    it("sends no condition once it is cleared", async () => {
        http.status(ENDPOINT, 204, "PATCH");
        const wrapper = await render(both);
        groupButtons()[0].click();
        await flushPromises();

        wrapper.findComponent({ name: "MonoSelect" }).vm.$emit("change", "");
        await submit();

        expect(http.lastCall(ENDPOINT)!.body).toMatchObject({ stack_id: "s1", condition: null });
    });

    it("holds the group still while a save is under way", async () => {
        http.hang(ENDPOINT);
        await render(both);

        $<HTMLButtonElement>('button[type="submit"]')!.click();
        await flushPromises();

        expect(groupButtons().every(button => button.disabled)).toBe(true);
    });

    it("offers the groups as one choice", async () => {
        await render(both);

        expect($('[role="radiogroup"]')!.getAttribute("aria-label")).toBe("pages.deck.copies.choose_label");
        expect(groupButtons().every(button => button.getAttribute("role") === "radio")).toBe(true);
    });

    it("sends neither a stack nor a condition for the plan", async () => {
        http.status(ENDPOINT, 204, "PATCH");
        await render(both);

        await submit();

        expect(http.lastCall(ENDPOINT)!.body).toEqual({ count: 2, finish: "nonfoil", language: "en", proxy: false });
    });

    it("shows a refused stack under the condition field", async () => {
        http.json(ENDPOINT, { errors: { stack_id: ["That stack isn't yours."] } }, 422, "PATCH");
        await render(both);
        groupButtons()[0].click();
        await flushPromises();

        await submit();

        expect(document.body.textContent).toContain("That stack isn't yours.");
    });
});

describe("DeckCardCopiesModal — apply to", () => {
    it("steps between one and the copies on offer", async () => {
        await render({
            integration: true,
            languages: LANGUAGES,
            conditions: CONDITIONS,
            claimed: 0,
            groups: [plan({ count: 2 })]
        });
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
