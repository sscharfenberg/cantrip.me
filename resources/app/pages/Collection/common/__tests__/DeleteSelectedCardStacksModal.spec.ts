import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { routerMock } from "@/test/inertia.ts";
import DeleteSelectedCardStacksModal from "../DeleteSelectedCardStacksModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const CONTAINER = "/containers/c1?search=bolt&page=3";

afterEach(() => history.replaceState(null, "", "/"));

describe("DeleteSelectedCardStacksModal", () => {
    it("deletes the selection and has the server send the user back to this page", async () => {
        history.replaceState(null, "", CONTAINER);
        mount(DeleteSelectedCardStacksModal, {
            props: { selectedIds: ["s1", "s2"], containerName: "Binder" },
            attachTo: document.body
        });

        // The modal teleports into <body>.
        document.querySelector<HTMLButtonElement>(".btn-primary")!.click();

        const [target, options] = routerMock.delete.mock.calls[0] as [string, { data: unknown }];
        const url = new URL(target, "http://x");
        expect(url.pathname).toBe("/collection/cardstack/delete-selected");
        expect(url.searchParams.get("return")).toBe(CONTAINER);
        expect(options.data).toEqual({ card_stack_ids: ["s1", "s2"] });
    });
});
