import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import type { CollectionCardStackRow } from "Types/collectionCardStackRow.ts";
import CollectionCardStacks from "../CollectionCardStacks.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const SEARCH = "/collection?search=sol+ring&set=lea&sort=name&dir=asc&page=2";

const row: CollectionCardStackRow = {
    id: "s1",
    name: "Sol Ring",
    set_name: "Limited Edition Alpha",
    set_code: "lea",
    set_path: null,
    collector_number: "269",
    amount: 1,
    condition: null,
    finish: "nonfoil",
    language: "en",
    art_crop: null,
    card_image_0: null,
    price: 1000,
    total_price: 1000,
    created_at: "2026-10-01T10:00:00+00:00",
    updated_at: "2026-10-01T10:00:00+00:00",
    claims: [{ deck_id: "d1", deck_name: "Krenko" }],
    container_name: "Binder",
    container_id: "c1"
};

const render = (overrides: Partial<CollectionCardStackRow> = {}) =>
    mount(CollectionCardStacks, {
        props: {
            table: {
                rows: [{ ...row, ...overrides }],
                total: 16,
                page: 2,
                pageSize: 25,
                sort: { key: "name", direction: "asc" },
                search: "sol ring",
                filters: { set: "lea" }
            },
            filterOptions: { languages: ["en"], conditions: [], finishes: [], rarities: [], types: [], sets: [] }
        },
        attachTo: document.body
    });

/** Open the row's actions popover and click the entry labelled `key`. */
const clickAction = async (wrapper: ReturnType<typeof render>, key: string): Promise<void> => {
    await wrapper.find(".dt-body__actions button").trigger("click");
    await flushPromises();
    const action = wrapper.findAll(".popover-list-item").find(item => item.text().includes(key));
    await action!.trigger("click");
};

beforeEach(() => {
    setPageProps({ auth: { user: { id: "u1" } }, currency: "eur" });
    history.replaceState(null, "", SEARCH);
});

afterEach(() => history.replaceState(null, "", "/"));

describe("CollectionCardStacks — coming back to the search", () => {
    it("opens the edit form with this page — search, filters, sort and page — to come back to", async () => {
        await clickAction(render(), "pages.edit_card.link");
        await flushPromises();

        const url = new URL(routerMock.visit.mock.calls[0][0] as string, "http://x");
        expect(url.pathname).toBe("/collection/cardstack/s1/edit");
        expect(url.searchParams.get("return")).toBe(SEARCH);
    });

    it("saves −/+ clicks still waiting before it opens the edit form", async () => {
        const http = installFetchMock().json("/api/collection/cardstack/s1/amount", { amount: 2 }, 200, "PATCH");
        const wrapper = render({ claims: [] });
        await wrapper.find(".dt-body__actions button").trigger("click");
        await flushPromises();
        await wrapper.findAll(".popover-list-multi button")[1].trigger("click");

        await clickAction(wrapper, "pages.edit_card.link");

        expect(routerMock.visit).not.toHaveBeenCalled();
        await flushPromises();
        expect(http.calls).toHaveLength(1);
        expect(routerMock.visit).toHaveBeenCalledOnce();

        // Let the menu's counting finish, so the next test starts afresh.
        wrapper.unmount();
        await flushPromises();
    });

    it("offers no −/+ for a stack a deck claims — its amount is the deck's business", async () => {
        const wrapper = render();

        await wrapper.find(".dt-body__actions button").trigger("click");
        await flushPromises();

        expect(wrapper.find(".popover-list-multi").exists()).toBe(false);
    });

    it("asks for the delete confirmation when − would take the last card", async () => {
        const wrapper = render({ claims: [] });

        await wrapper.find(".dt-body__actions button").trigger("click");
        await flushPromises();
        await wrapper.find(".popover-list-multi button").trigger("click");

        expect(wrapper.findComponent({ name: "DeleteCardStackModal" }).exists()).toBe(true);
    });

    it("asks where the stack should go before it unclaims", async () => {
        const wrapper = render();

        await clickAction(wrapper, "pages.collection.claim_badge.unclaim");

        expect(routerMock.delete).not.toHaveBeenCalled();
        const modal = wrapper.findComponent({ name: "UnclaimCardStackModal" });
        expect(modal.props("currentContainerId")).toBe("c1");
        expect(modal.props("currentContainerName")).toBe("Binder");
    });
});
