import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick, ref } from "vue";
import type { Ref } from "vue";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { createTestI18n } from "@/test/i18n.ts";
import { setPageProps } from "@/test/inertia.ts";
import { withSetup } from "@/test/withSetup.ts";
import type { Container } from "Types/container.ts";
import type { UseContainerReturn } from "../useContainer.ts";
import { useContainer } from "../useContainer.ts";
import { useToast } from "../useToast.ts";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const CONTAINER_TYPES = ["binder", "deckbox", "box", "other"];

const container = (id: string, overrides: Partial<Container> = {}): Container => ({
    id,
    name: id,
    description: null,
    type: "binder",
    visibility: "private",
    custom_type: null,
    sort: 0,
    defaultCard: null,
    totalCards: 0,
    totalPrice: 0,
    ...overrides
});

let http: FetchMock;

/** Messages of the toasts currently on screen. */
const toastMessages = (): string[] => useToast().activeToasts.value.map(toast => toast.message);

beforeEach(() => {
    vi.useFakeTimers();
    setPageProps({ csrfToken: "csrf-token" });
    http = installFetchMock();
    http.json("/containers/sort", { ok: true });

    // Toast state is a module-level singleton shared with the rest of the app,
    // so it survives between tests in this file. Drain it rather than resetting
    // the module — `useContainer` holds a reference to the same instance.
    const { activeToasts, removeToast } = useToast();
    for (const toast of [...activeToasts.value]) {
        removeToast(toast.id);
    }
});

afterEach(() => {
    vi.useRealTimers();
});

/** Mount the composable inside a component instance, with i18n installed. */
const setup = (containers: Container[]): { state: UseContainerReturn; source: Ref<Container[]> } => {
    const source: Ref<Container[]> = ref(containers);
    const [state] = withSetup(() => useContainer(source, CONTAINER_TYPES), [createTestI18n()]);
    return { state, source };
};

const names = (containers: Container[]): string[] => containers.map(c => c.name);

describe("useContainer — local copy", () => {
    it("mirrors the server list on creation", () => {
        const { state } = setup([container("a"), container("b")]);

        expect(names(state.localContainers.value)).toEqual(["a", "b"]);
    });

    it("copies rather than aliasing, so a reorder does not mutate the Inertia prop", () => {
        const source = [container("a"), container("b")];
        const { state } = setup(source);

        state.localContainers.value.reverse();

        expect(names(source)).toEqual(["a", "b"]);
    });

    it("re-syncs when Inertia refreshes the page props", async () => {
        // e.g. after a delete elsewhere on the page.
        const { state, source } = setup([container("a"), container("b")]);

        source.value = [container("a")];
        await nextTick();

        expect(names(state.localContainers.value)).toEqual(["a"]);
    });
});

describe("useContainer — reordering", () => {
    it("applies the new order optimistically, before the server hears about it", () => {
        const { state } = setup([container("a"), container("b"), container("c")]);

        state.handleReorder([container("c"), container("b"), container("a")]);

        expect(names(state.localContainers.value)).toEqual(["c", "b", "a"]);
        expect(state.isSaving.value).toBe(true);
        expect(http.calls).toHaveLength(0);
    });

    it("slots a filtered reorder back into the positions those items occupied", async () => {
        // The drag list only ever contains the visible subset; hidden items
        // must keep their own slots.
        const all = [container("a"), container("hidden", { type: "deckbox" }), container("c")];
        const { state } = setup(all);
        state.toggleType("deckbox");

        state.handleReorder([container("c"), container("a")]);

        expect(names(state.localContainers.value)).toEqual(["c", "hidden", "a"]);
    });

    it("sends the whole order, including containers the filter is hiding", async () => {
        // Sending only the visible subset would drop every hidden container's
        // sort position on the floor.
        const all = [container("a"), container("hidden", { type: "deckbox" }), container("c")];
        const { state } = setup(all);
        state.toggleType("deckbox");

        state.handleReorder([container("c"), container("a")]);
        await vi.advanceTimersByTimeAsync(500);

        expect(http.lastCall("/containers/sort")?.body).toEqual({ order: ["c", "hidden", "a"] });
    });

    it("debounces the PATCH by half a second", async () => {
        const { state } = setup([container("a"), container("b")]);

        state.handleReorder([container("b"), container("a")]);
        await vi.advanceTimersByTimeAsync(499);
        expect(http.calls).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1);
        expect(http.calls).toHaveLength(1);
    });

    it("sends only the final order after a flurry of drags", async () => {
        const { state } = setup([container("a"), container("b"), container("c")]);

        state.handleReorder([container("b"), container("a"), container("c")]);
        await vi.advanceTimersByTimeAsync(200);
        state.handleReorder([container("c"), container("b"), container("a")]);
        await vi.advanceTimersByTimeAsync(500);

        expect(http.calls).toHaveLength(1);
        expect(http.lastCall()?.body).toEqual({ order: ["c", "b", "a"] });
    });

    it("PATCHes the id order with the CSRF token", async () => {
        const { state } = setup([container("a"), container("b")]);

        state.handleReorder([container("b"), container("a")]);
        await vi.advanceTimersByTimeAsync(500);

        expect(http.lastCall("/containers/sort")).toMatchObject({
            method: "PATCH",
            headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": "csrf-token" },
            body: { order: ["b", "a"] }
        });
    });

    it("clears the saving flag once the server confirms", async () => {
        const { state } = setup([container("a"), container("b")]);

        state.handleReorder([container("b"), container("a")]);
        await vi.advanceTimersByTimeAsync(500);

        expect(state.isSaving.value).toBe(false);
    });

    it("toasts and stops saving when the server reports a failure", async () => {
        http.json("/containers/sort", { ok: false });
        const { state } = setup([container("a"), container("b")]);

        state.handleReorder([container("b"), container("a")]);
        await vi.advanceTimersByTimeAsync(500);

        expect(state.isSaving.value).toBe(false);
        expect(toastMessages()).toEqual(["pages.containers.sort_error"]);
    });

    it("toasts when the request itself fails", async () => {
        http.reject("/containers/sort");
        const { state } = setup([container("a"), container("b")]);

        state.handleReorder([container("b"), container("a")]);
        await vi.advanceTimersByTimeAsync(500);

        expect(state.isSaving.value).toBe(false);
        expect(toastMessages()).toEqual(["pages.containers.sort_error"]);
    });

    it("does not toast when a request is superseded", async () => {
        // An abort is the composable cancelling itself, not an error the user
        // should see.
        http.hang("/containers/sort");
        const { state } = setup([container("a"), container("b")]);
        state.handleReorder([container("b"), container("a")]);
        await vi.advanceTimersByTimeAsync(500);

        http.json("/containers/sort", { ok: true });
        state.handleReorder([container("a"), container("b")]);
        await vi.advanceTimersByTimeAsync(500);

        expect(toastMessages()).toEqual([]);
    });
});

describe("useContainer — type filter", () => {
    it("starts with every present type selected, i.e. showing everything", () => {
        const { state } = setup([container("a"), container("b", { type: "deckbox" })]);

        expect([...state.activeTypes.value].sort()).toEqual(["binder", "deckbox"]);
        expect(names(state.filteredContainers.value)).toEqual(["a", "b"]);
    });

    it("lists only the types actually present, not every known type", () => {
        const { state } = setup([container("a")]);

        expect(state.usedTypes.value).toEqual(["binder"]);
    });

    it("treats a custom type as its own filter key", () => {
        // "other" containers carry a user-typed label; filtering by the literal
        // "other" would lump unrelated things together.
        const { state } = setup([
            container("a", { type: "other", custom_type: "Shoebox" }),
            container("b", { type: "other", custom_type: "Deck Vault" })
        ]);

        expect(state.usedTypes.value).toEqual(["Shoebox", "Deck Vault"]);
    });

    it("falls back to the bare type when a custom label is missing", () => {
        const { state } = setup([container("a", { type: "other", custom_type: null })]);

        expect(state.usedTypes.value).toEqual(["other"]);
    });

    it("hides a type when it is toggled off, and shows it again when toggled back", () => {
        const { state } = setup([container("a"), container("b", { type: "deckbox" })]);

        state.toggleType("deckbox");
        expect(names(state.filteredContainers.value)).toEqual(["a"]);

        state.toggleType("deckbox");
        expect(names(state.filteredContainers.value)).toEqual(["a", "b"]);
    });

    it("shows a container whose type first appears on a page-prop refresh", async () => {
        // Creating the first deckbox elsewhere on the page would otherwise add
        // its filter chip while leaving the container itself hidden until a
        // full reload.
        const { state, source } = setup([container("a")]);

        source.value = [container("a"), container("b", { type: "deckbox" })];
        await nextTick();

        expect(state.usedTypes.value).toEqual(["binder", "deckbox"]);
        expect(names(state.filteredContainers.value)).toEqual(["a", "b"]);
    });

    it("leaves a type the user switched off switched off across a refresh", async () => {
        const { state, source } = setup([container("a"), container("b", { type: "deckbox" })]);
        state.toggleType("deckbox");

        source.value = [container("a"), container("b", { type: "deckbox" }), container("c")];
        await nextTick();

        expect(names(state.filteredContainers.value)).toEqual(["a", "c"]);
    });

    it("shows nothing when every type is switched off", () => {
        const { state } = setup([container("a")]);

        state.toggleType("binder");

        expect(state.filteredContainers.value).toEqual([]);
    });
});

describe("useContainer — name search", () => {
    it("matches case-insensitively on a substring", () => {
        const { state } = setup([container("a", { name: "Modern Binder" }), container("b", { name: "Commander" })]);

        state.search.value = "MODERN";

        expect(names(state.filteredContainers.value)).toEqual(["Modern Binder"]);
    });

    it("shows everything again when the box is cleared", () => {
        const { state } = setup([container("a", { name: "Modern Binder" }), container("b", { name: "Commander" })]);
        state.search.value = "modern";

        state.search.value = "";

        expect(state.filteredContainers.value).toHaveLength(2);
    });

    it("applies alongside the type filter, not instead of it", () => {
        const { state } = setup([
            container("a", { name: "Modern Binder" }),
            container("b", { name: "Modern Deckbox", type: "deckbox" })
        ]);

        state.search.value = "modern";
        state.toggleType("deckbox");

        expect(names(state.filteredContainers.value)).toEqual(["Modern Binder"]);
    });
});

describe("useContainer — typeLabel", () => {
    it("translates a known container type", () => {
        const { state } = setup([container("a")]);

        expect(state.typeLabel("binder")).toBe("enums.container_type.binder");
    });

    it("passes a user-defined label through untranslated", () => {
        const { state } = setup([container("a")]);

        expect(state.typeLabel("Shoebox")).toBe("Shoebox");
    });
});
