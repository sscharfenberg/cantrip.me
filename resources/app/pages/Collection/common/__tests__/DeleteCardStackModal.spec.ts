import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { routerMock } from "@/test/inertia.ts";
import type { CardStackRow } from "Types/cardStackRow.ts";
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

        const url = new URL(routerMock.delete.mock.calls[0][0] as string, "http://x");
        expect(url.pathname).toBe("/collection/cardstack/s1");
        expect(url.searchParams.get("return")).toBe(SEARCH);
    });
});
