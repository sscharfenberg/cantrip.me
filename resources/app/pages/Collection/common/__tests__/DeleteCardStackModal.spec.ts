import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import type { CardStackRow } from "Types/cardStackRow.ts";
import CardStackAmountButtons from "../CardStackAmountButtons.vue";
import DeleteCardStackModal from "../DeleteCardStackModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const SEARCH = "/collection?search=sol+ring&sort=name&dir=asc&page=2";

afterEach(() => history.replaceState(null, "", "/"));

describe("DeleteCardStackModal", () => {
    it("deletes the stack and has the server send the user back to this page", async () => {
        history.replaceState(null, "", SEARCH);
        mount(DeleteCardStackModal, {
            props: {
                cardStack: {
                    id: "s1",
                    name: "Sol Ring",
                    amount: 1,
                    set_code: "lea",
                    set_name: "Alpha"
                } as CardStackRow,
                containerName: "Binder"
            },
            attachTo: document.body
        });

        // The modal teleports into <body>.
        document.querySelector<HTMLButtonElement>(".btn-primary")!.click();
        await flushPromises();

        const url = new URL(routerMock.delete.mock.calls[0][0] as string, "http://x");
        expect(url.pathname).toBe("/collection/cardstack/s1");
        expect(url.searchParams.get("return")).toBe(SEARCH);
    });

    it("saves −/+ clicks still on their way before it deletes", async () => {
        setPageProps({ csrfToken: "token" });
        const http = installFetchMock().json("/api/collection/cardstack/s9/amount", { amount: 3 }, 200, "PATCH");
        const rows = [{ id: "s9", amount: 2, price: 1, total_price: 2 }];
        const buttons = mount(CardStackAmountButtons, { props: { row: { ...rows[0] }, rows, totals: [] } });
        await buttons.findAll("button")[1].trigger("click");
        mount(DeleteCardStackModal, {
            props: {
                cardStack: {
                    id: "s9",
                    name: "Sol Ring",
                    amount: 3,
                    set_code: "lea",
                    set_name: "Alpha"
                } as CardStackRow,
                containerName: "Binder"
            },
            attachTo: document.body
        });

        document.querySelector<HTMLButtonElement>(".btn-primary")!.click();

        expect(routerMock.delete).not.toHaveBeenCalled();
        await flushPromises();
        expect(http.calls).toHaveLength(1);
        expect(routerMock.delete).toHaveBeenCalledOnce();
    });
});
