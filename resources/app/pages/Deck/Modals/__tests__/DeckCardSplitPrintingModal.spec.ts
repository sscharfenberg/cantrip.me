import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import DeckCardSplitPrintingModal from "../DeckCardSplitPrintingModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

/******************************************************************************
 * The modal renders through `<Modal>`, which teleports into `<body>` — so
 * assertions go through `document`, not through the wrapper, whose own subtree
 * is just the teleport anchor.
 *****************************************************************************/

const DECK_ID = "deck-1";
const CARD_ID = "card-1";
const PRINTINGS_URL = `/api/decks/${DECK_ID}/cards/${CARD_ID}/printings`;
const SPLIT_URL = `/api/decks/${DECK_ID}/cards/${CARD_ID}/split`;

const printing = (id: string, overrides: Record<string, unknown> = {}) => ({
    id,
    name: "Sol Ring",
    card_image_0: null,
    card_image_1: null,
    artist: null,
    cn: id,
    finishes: ["nonfoil"],
    set: { name: "Commander 2021", code: "c21", path: null },
    in_collection: false,
    is_current: false,
    ...overrides
});

const PRINTINGS = [
    printing("p-old"),
    printing("p-current", { is_current: true }),
    printing("p-owned", { in_collection: true })
];

let http: FetchMock;

beforeEach(() => {
    setPageProps({ csrfToken: "csrf-token" });
    http = installFetchMock();
    http.json(PRINTINGS_URL, PRINTINGS);
});

const open = (quantity = 4) =>
    mount(DeckCardSplitPrintingModal, {
        props: { deckId: DECK_ID, cardId: CARD_ID, name: "Sol Ring", quantity },
        attachTo: document.body
    });

/** Mount and wait for the printings request to land. */
const render = async (quantity = 4) => {
    const wrapper = open(quantity);
    await flushPromises();
    return wrapper;
};

/** One row per printing, in the order the modal chose to show them. */
const rows = (): HTMLElement[] => [...document.querySelectorAll<HTMLElement>(".split-printing__item")];

/** The quantity currently assigned to each row. */
const assigned = (): number[] =>
    rows().map(row => Number(row.querySelector(".split-printing__stepper span")?.textContent));

const stepper = (index: number, direction: "increment" | "decrement"): HTMLButtonElement =>
    rows()[index].querySelector(`[aria-label="pages.deck.card_quantity.${direction}"]`) as HTMLButtonElement;

const submitButton = (): HTMLButtonElement | null => document.querySelector(".modal-dialog__footer button");

const totalText = (): string => document.querySelector(".split-printing__total")?.textContent?.trim() ?? "";

/** Click a stepper and let Vue re-render. */
const press = async (button: HTMLButtonElement): Promise<void> => {
    button.click();
    await flushPromises();
};

describe("DeckCardSplitPrintingModal — loading", () => {
    it("asks the server for this card's printings", async () => {
        await render();

        expect(http.lastCall(PRINTINGS_URL)?.headers).toMatchObject({ Accept: "application/json" });
    });

    it("shows a spinner until they arrive", () => {
        http.hang(PRINTINGS_URL);
        open();

        expect(document.querySelector(".loading-spinner")).not.toBeNull();
        expect(rows()).toHaveLength(0);
    });

    it("surfaces a failure rather than an empty list", async () => {
        http.status(PRINTINGS_URL, 500);
        await render();

        expect(document.querySelector(".split-printing__error")).not.toBeNull();
        expect(submitButton()).toBeNull();
    });

    it("aborts the request if the modal closes first", async () => {
        http.hang(PRINTINGS_URL);
        const wrapper = open();

        wrapper.unmount();
        await flushPromises();

        expect(http.lastCall(PRINTINGS_URL)?.signal?.aborted).toBe(true);
    });
});

describe("DeckCardSplitPrintingModal — the starting assignment", () => {
    it("pins the current printing to the top, so the common tweak is one click away", async () => {
        await render();

        expect(assigned()).toEqual([4, 0, 0]);
    });

    it("puts every copy on the printing the card currently uses", async () => {
        await render(4);

        expect(assigned()).toEqual([4, 0, 0]);
        expect(totalText()).toContain("4 / 4");
    });

    it("cannot be applied yet — a split needs at least two printings", async () => {
        await render(4);

        expect(submitButton()?.disabled).toBe(true);
        expect(document.querySelector(".split-printing__total--invalid")).not.toBeNull();
    });
});

describe("DeckCardSplitPrintingModal — redistributing copies", () => {
    it("steals a copy from the current printing when the pool is full", async () => {
        // The total is fixed at the card's quantity, so adding somewhere always
        // means taking from somewhere else.
        const wrapper = await render(4);

        await press(stepper(1, "increment"));

        expect(assigned()).toEqual([3, 1, 0]);
        expect(totalText()).toContain("4 / 4");
        wrapper.unmount();
    });

    it("becomes applicable once two printings hold copies", async () => {
        await render(4);

        await press(stepper(1, "increment"));

        expect(submitButton()?.disabled).toBe(false);
        expect(document.querySelector(".split-printing__total--invalid")).toBeNull();
    });

    it("takes a copy out of the pool on decrement rather than handing it back", async () => {
        // Asymmetric on purpose: increment has to steal because the total is
        // already at the limit, but decrement leaves the copy unassigned so the
        // user can place it wherever they meant to.
        await render(4);
        await press(stepper(1, "increment"));

        await press(stepper(1, "decrement"));

        expect(assigned()).toEqual([3, 0, 0]);
        expect(totalText()).toContain("3 / 4");
    });

    it("stays unsubmittable while copies are still unassigned", async () => {
        await render(4);
        await press(stepper(1, "increment"));

        await press(stepper(1, "decrement"));

        expect(submitButton()?.disabled).toBe(true);
        expect(document.querySelector(".split-printing__total--invalid")).not.toBeNull();
    });

    it("becomes submittable again once the loose copy is placed", async () => {
        await render(4);
        await press(stepper(1, "increment"));
        await press(stepper(1, "decrement"));

        await press(stepper(2, "increment"));

        expect(assigned()).toEqual([3, 0, 1]);
        expect(submitButton()?.disabled).toBe(false);
    });

    it("disables decrement on a printing holding nothing", async () => {
        await render(4);

        expect(stepper(1, "decrement").disabled).toBe(true);
        expect(stepper(0, "decrement").disabled).toBe(false);
    });

    it("disables increment on the printing that already holds every copy", async () => {
        // The pool is full, and this printing cannot steal from itself — so
        // its + would be a silent no-op. Its neighbours' + still work: they
        // steal from it.
        await render(1);

        expect(stepper(0, "increment").disabled).toBe(true);
        expect(stepper(1, "increment").disabled).toBe(false);
    });

    it("spreads a larger quantity across three printings", async () => {
        await render(4);

        await press(stepper(1, "increment"));
        await press(stepper(2, "increment"));

        expect(assigned()).toEqual([2, 1, 1]);
    });
});

describe("DeckCardSplitPrintingModal — request failures", () => {
    it("shows the error state when the connection drops", async () => {
        http.reject(PRINTINGS_URL, new TypeError("Failed to fetch"));

        await render();

        expect(document.querySelector(".split-printing__error")).not.toBeNull();
        expect(rows()).toHaveLength(0);
    });
});

describe("DeckCardSplitPrintingModal — filtering", () => {
    it("shows every printing by default", async () => {
        await render();

        expect(rows()).toHaveLength(3);
    });

    it("narrows to owned printings when the toggle is switched on", async () => {
        await render();
        const toggle = document.querySelector("#only_collection_printings") as HTMLInputElement;

        toggle.checked = true;
        toggle.dispatchEvent(new Event("change"));
        await flushPromises();

        expect(rows()).toHaveLength(1);
    });

    it("keeps the hidden printings' assignments, so filtering is not destructive", async () => {
        await render(4);
        await press(stepper(1, "increment"));
        const toggle = document.querySelector("#only_collection_printings") as HTMLInputElement;

        toggle.checked = true;
        toggle.dispatchEvent(new Event("change"));
        await flushPromises();

        // Total still reads 4 of 4 even though only one row is on screen.
        expect(totalText()).toContain("4 / 4");
    });
});

describe("DeckCardSplitPrintingModal — submitting", () => {
    /** Make the assignment valid: one copy moved off the current printing. */
    const splitOneOff = async (): Promise<void> => {
        await press(stepper(1, "increment"));
    };

    it("sends only the printings that ended up with copies", async () => {
        await render(4);
        await splitOneOff();

        await press(submitButton() as HTMLButtonElement);

        const body = http.lastCall(SPLIT_URL)?.body as { splits: { default_card_id: string; quantity: number }[] };
        expect(body.splits).toEqual(
            expect.arrayContaining([
                { default_card_id: "p-current", quantity: 3 },
                { default_card_id: "p-old", quantity: 1 }
            ])
        );
        expect(body.splits).toHaveLength(2);
    });

    it("PATCHes with the CSRF token", async () => {
        await render(4);
        await splitOneOff();

        await press(submitButton() as HTMLButtonElement);

        expect(http.lastCall(SPLIT_URL)).toMatchObject({
            method: "PATCH",
            headers: { "X-CSRF-TOKEN": "csrf-token", Accept: "application/json" }
        });
    });

    it("reloads the deck once the server accepts", async () => {
        await render(4);
        await splitOneOff();

        await press(submitButton() as HTMLButtonElement);

        expect(routerMock.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ["cards", "deck", "violations", "tokens"] })
        );
    });

    it("closes only after the reload has finished, so the page is already current", async () => {
        const wrapper = await render(4);
        await splitOneOff();

        await press(submitButton() as HTMLButtonElement);
        expect(wrapper.emitted("close")).toBeUndefined();

        const options = routerMock.reload.mock.calls[0][0] as { onFinish: () => void };
        options.onFinish();
        await flushPromises();

        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("disables the button while the split is in flight, so it cannot be sent twice", async () => {
        // Note what this does *not* pin: `applySplit`'s own
        // `|| submitting.value` guard is unreachable from a test, because the
        // disabled attribute stops the second click before the handler runs.
        // The guard is belt-and-braces behind this attribute.
        http.hang(SPLIT_URL);
        await render(4);
        await splitOneOff();
        const button = submitButton() as HTMLButtonElement;

        await press(button);

        expect(button.disabled).toBe(true);

        await press(button);

        expect(http.callsTo(SPLIT_URL)).toHaveLength(1);
    });

    it("covers the modal while submitting, so the list cannot be edited underneath", async () => {
        http.hang(SPLIT_URL);
        await render(4);
        await splitOneOff();

        await press(submitButton() as HTMLButtonElement);

        expect(document.querySelector(".split-printing__overlay")).not.toBeNull();
    });

    it("stays open when the server refuses", async () => {
        http.status(SPLIT_URL, 422);
        const wrapper = await render(4);
        await splitOneOff();

        await press(submitButton() as HTMLButtonElement);

        expect(routerMock.reload).not.toHaveBeenCalled();
        expect(wrapper.emitted("close")).toBeUndefined();
        expect(submitButton()?.disabled).toBe(false);
    });
});
