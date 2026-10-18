import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { reactive } from "vue";
import { installFetchMock, type FetchMock } from "@/test/http.ts";
import { setTestMessages } from "@/test/i18n.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import { type AmountRow, settleAmountChanges } from "Composables/useCardStackAmount.ts";
import CardStackAmountButtons from "../CardStackAmountButtons.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const toast = vi.hoisted(() => vi.fn());
vi.mock("Composables/useToast.ts", () => ({ useToast: () => ({ addToast: toast }) }));

const TOTALS = ["stats"];

let http: FetchMock;
let rows: AmountRow[];
/** A fresh stack id per test — the counting is kept per stack, across menus. */
let id = "";
let next = 0;
const endpoint = (): string => `/api/collection/cardstack/${id}/amount`;

beforeEach(() => {
    vi.useFakeTimers();
    http = installFetchMock();
    setPageProps({ csrfToken: "token" });
    id = `s${++next}`;
});

afterEach(() => vi.useRealTimers());

/** Open the menu for a stack holding `amount` cards — the first time, the table row too. */
const render = (amount?: number) => {
    if (amount !== undefined) rows = reactive([{ id, amount, price: 2.5, total_price: 2.5 * amount }]);
    return mount(CardStackAmountButtons, { props: { row: { ...rows[0] }, rows, totals: TOTALS } });
};

type Wrapper = ReturnType<typeof render>;
const minus = (wrapper: Wrapper) => wrapper.findAll("button")[0].trigger("click");
const plus = (wrapper: Wrapper) => wrapper.findAll("button")[1].trigger("click");
/** Let the debounce run out and the requests settle. */
const settle = async (): Promise<void> => {
    vi.advanceTimersByTime(500);
    await flushPromises();
};
/**
 * Answer PATCHes by hand: `answer(4)` confirms the oldest one still out
 * with that amount, `answer(response)` answers it with `response`.
 */
const answerByHand = () => {
    const waiting: ((response: Response) => void)[] = [];
    http.on(endpoint(), () => new Promise<Response>(resolve => waiting.push(resolve)), "PATCH");
    return async (reply: number | Response): Promise<void> => {
        waiting.shift()!(typeof reply === "number" ? Response.json({ amount: reply }) : reply);
        await flushPromises();
    };
};

describe("CardStackAmountButtons — changing the amount", () => {
    it("shows each click in the table at once, amount and total", async () => {
        http.json(endpoint(), { amount: 5 }, 200, "PATCH");
        const wrapper = render(3);

        await plus(wrapper);
        await plus(wrapper);

        expect(rows[0].amount).toBe(5);
        expect(rows[0].total_price).toBe(12.5);
    });

    it("sends a burst of clicks as one change once they pause", async () => {
        http.json(endpoint(), { amount: 4 }, 200, "PATCH");
        const wrapper = render(3);

        await plus(wrapper);
        await plus(wrapper);
        await minus(wrapper);
        vi.advanceTimersByTime(499);
        expect(http.calls).toHaveLength(0);

        await settle();

        expect(http.calls).toHaveLength(1);
        expect(http.calls[0]).toMatchObject({ method: "PATCH", body: { delta: 1 } });
        expect(http.calls[0].headers["X-CSRF-TOKEN"]).toBe("token");
    });

    it("reloads only the page's sums while the menu is open — a reloaded table would re-sort under it", async () => {
        http.json(endpoint(), { amount: 4 }, 200, "PATCH");
        const wrapper = render(3);

        await plus(wrapper);
        await settle();

        expect(routerMock.reload).toHaveBeenCalledExactlyOnceWith({ only: TOTALS });
    });

    it("reloads the table once the menu closes and everything is saved", async () => {
        http.json(endpoint(), { amount: 4 }, 200, "PATCH");
        const wrapper = render(3);
        await plus(wrapper);
        await settle();

        wrapper.unmount();
        await flushPromises();

        expect(routerMock.reload).toHaveBeenLastCalledWith({ only: ["table", ...TOTALS] });
    });

    it("reloads nothing when the menu closes without a change", async () => {
        render(3).unmount();
        await flushPromises();

        expect(routerMock.reload).not.toHaveBeenCalled();
    });

    it("sends nothing when the clicks cancel out", async () => {
        const wrapper = render(3);

        await plus(wrapper);
        await minus(wrapper);
        await settle();

        expect(http.calls).toHaveLength(0);
    });

    it("sends one request at a time, so they add up on the server", async () => {
        const answer = answerByHand();
        const wrapper = render(3);
        await plus(wrapper);
        await settle();
        await plus(wrapper);
        await settle();

        expect(http.calls).toHaveLength(1);

        await answer(4);

        expect(http.calls).toHaveLength(2);
        expect(http.calls.map(call => call.body)).toEqual([{ delta: 1 }, { delta: 1 }]);
        await answer(5);
    });

    it("takes the server's amount when it differs — another tab changed the stack", async () => {
        http.json(endpoint(), { amount: 10 }, 200, "PATCH");
        const wrapper = render(3);

        await plus(wrapper);
        await settle();

        expect(rows[0].amount).toBe(10);
    });

    it("stops at the largest amount a stack holds", () => {
        expect(render(65535).findAll("button")[1].attributes("disabled")).toBeDefined();
    });

    it("goes back to the last amount the server confirmed when a change fails, and says so", async () => {
        http.json(endpoint(), { amount: 4 }, 200, "PATCH");
        const wrapper = render(3);
        await plus(wrapper);
        await settle();

        http.status(endpoint(), 500, "PATCH");
        await plus(wrapper);
        await plus(wrapper);
        await settle();

        expect(rows[0].amount).toBe(4);
        expect(rows[0].total_price).toBe(10);
        expect(toast).toHaveBeenCalledWith("pages.collection.amount.error", "error");
    });

    it("sends what is still waiting when the menu closes", async () => {
        http.json(endpoint(), { amount: 4 }, 200, "PATCH");
        const wrapper = render(3);

        await plus(wrapper);
        wrapper.unmount();
        await flushPromises();

        expect(http.calls).toHaveLength(1);
        expect(http.calls[0].body).toEqual({ delta: 1 });
    });

    it("continues from a request still out when the menu is opened again", async () => {
        const answer = answerByHand();
        const first = render(1);
        await plus(first);
        first.unmount();
        await flushPromises();

        // Reopened while the +1 is out: it counts from 2, so − removes a card.
        const second = render();
        expect(second.findAll("button")[0].attributes("aria-label")).toBe("pages.collection.amount.decrement");

        // The +1 fails: back to the 1 the server holds, so − is a delete again.
        await answer(new Response(null, { status: 500 }));
        await minus(second);

        expect(rows[0].amount).toBe(1);
        expect(second.emitted("delete")).toHaveLength(1);
    });
});

describe("CardStackAmountButtons — the last card", () => {
    it("asks for the delete confirmation instead of taking it", async () => {
        const wrapper = render(1);

        await minus(wrapper);
        await settle();

        expect(wrapper.emitted("delete")).toHaveLength(1);
        expect(rows[0].amount).toBe(1);
        expect(http.calls).toHaveLength(0);
    });

    it("sends the clicks still waiting before it asks", async () => {
        http.json(endpoint(), { amount: 1 }, 200, "PATCH");
        const wrapper = render(2);

        await minus(wrapper);
        await minus(wrapper);
        await flushPromises();

        expect(wrapper.emitted("delete")).toHaveLength(1);
        expect(http.calls).toHaveLength(1);
        expect(http.calls[0].body).toEqual({ delta: -1 });
    });

    it("labels − as the delete it becomes", async () => {
        http.json(endpoint(), { amount: 1 }, 200, "PATCH");
        const wrapper = render(2);

        expect(wrapper.findAll("button")[0].attributes("aria-label")).toBe("pages.collection.amount.decrement");

        await minus(wrapper);

        expect(wrapper.findAll("button")[0].attributes("aria-label")).toBe("pages.container_page.delete.link");
    });
});

describe("CardStackAmountButtons — telling a screen reader", () => {
    it("announces the new amount after a click, and nothing when the menu opens", async () => {
        setTestMessages({ de: { pages: { collection: { amount: { announce: "{count} Karte | {count} Karten" } } } } });
        http.json(endpoint(), { amount: 4 }, 200, "PATCH");
        const wrapper = render(3);

        expect(wrapper.find("[aria-live]").text()).toBe("");

        await plus(wrapper);

        expect(wrapper.find("[aria-live]").text()).toBe("4 Karten");
    });
});

describe("settleAmountChanges", () => {
    it("sends every waiting click now and resolves once the server has them", async () => {
        http.json(endpoint(), { amount: 4 }, 200, "PATCH");
        const wrapper = render(3);
        await plus(wrapper);

        const settled = settleAmountChanges();
        await flushPromises();
        await settled;

        expect(http.calls).toHaveLength(1);
        expect(http.calls[0].body).toEqual({ delta: 1 });
    });
});
