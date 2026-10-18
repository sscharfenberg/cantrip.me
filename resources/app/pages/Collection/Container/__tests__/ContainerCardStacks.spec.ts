import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import type { CardStackRow } from "Types/cardStackRow.ts";
import ContainerCardStacks from "../ContainerCardStacks.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const CONTAINER = "/containers/c1?search=bolt&sort=name&dir=asc&page=3";

const row: CardStackRow = {
    id: "s1",
    name: "Lightning Bolt",
    set_name: "Limited Edition Alpha",
    set_code: "lea",
    set_path: null,
    collector_number: "161",
    amount: 1,
    condition: null,
    finish: "nonfoil",
    language: "en",
    art_crop: null,
    card_image_0: null,
    price: 500,
    total_price: 500,
    created_at: "2026-10-01T10:00:00+00:00",
    updated_at: "2026-10-01T10:00:00+00:00",
    claims: [{ deck_id: "d1", deck_name: "Burn" }]
};

const render = (overrides: Partial<CardStackRow> = {}) =>
    mount(ContainerCardStacks, {
        props: {
            table: {
                rows: [{ ...row, ...overrides }],
                total: 51,
                page: 3,
                pageSize: 25,
                sort: { key: "name", direction: "asc" },
                search: "bolt",
                filters: null
            },
            filterOptions: { languages: ["en"], conditions: [], finishes: [], rarities: [], types: [], sets: [] },
            baseUrl: "/containers/c1",
            containerId: "c1",
            containerName: "Binder",
            containers: [{ id: "c1", name: "Binder" }],
            isOwner: true
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
    history.replaceState(null, "", CONTAINER);
});

afterEach(() => history.replaceState(null, "", "/"));

describe("ContainerCardStacks — coming back to the container", () => {
    it("opens the edit form with this page — search, sort and page — to come back to", async () => {
        await clickAction(render(), "pages.edit_card.link");
        await flushPromises();

        const url = new URL(routerMock.visit.mock.calls[0][0] as string, "http://x");
        expect(url.pathname).toBe("/collection/cardstack/s1/edit");
        expect(url.searchParams.get("return")).toBe(CONTAINER);
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
