import { afterEach, describe, expect, it, vi } from "vitest";
import { ref } from "vue";
import { routerMock } from "@/test/inertia.ts";
import { withSetup } from "@/test/withSetup.ts";
import { useHashTabs } from "Composables/useHashTabs.ts";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const IDS = ["commander", "legacy", "vintage"];

/** Point the address bar at `url` without firing `hashchange`, as a fresh page load would. */
const visit = (url: string): void => history.replaceState(null, "", url);

/** Change the hash the way a user editing the address bar does. */
const editHash = (hash: string): void => {
    visit(`/b+r#${hash}`);
    window.dispatchEvent(new HashChangeEvent("hashchange"));
};

afterEach(() => visit("/"));

describe("useHashTabs — the initial tab", () => {
    it("opens the tab the hash names", () => {
        visit("/b+r#legacy");

        const [{ active }] = withSetup(() => useHashTabs(IDS));

        expect(active.value).toBe("legacy");
    });

    it("opens the first tab without a hash", () => {
        visit("/b+r");

        const [{ active }] = withSetup(() => useHashTabs(IDS));

        expect(active.value).toBe("commander");
    });

    it("opens the first tab when the hash names none of them", () => {
        visit("/b+r#modern");

        const [{ active }] = withSetup(() => useHashTabs(IDS));

        expect(active.value).toBe("commander");
    });

    it("matches the hash regardless of case", () => {
        visit("/b+r#Legacy");

        const [{ active }] = withSetup(() => useHashTabs(IDS));

        expect(active.value).toBe("legacy");
    });

    it("falls back to the first tab on a hash that is not valid URI encoding", () => {
        visit("/b+r#100%");

        const [{ active }] = withSetup(() => useHashTabs(IDS));

        expect(active.value).toBe("commander");
    });

    it("leaves a missing hash missing — the URL only changes when a tab is picked", () => {
        visit("/b+r");

        withSetup(() => useHashTabs(IDS));

        expect(routerMock.replace).not.toHaveBeenCalled();
    });
});

describe("useHashTabs — selecting", () => {
    it("opens the tab and writes it to the hash through Inertia, keeping path, query, state and scroll", () => {
        visit("/b+r?ref=home");
        const [{ active, select }] = withSetup(() => useHashTabs(IDS));

        select("vintage");

        expect(active.value).toBe("vintage");
        // A replace, not a push: switching tabs adds no history entry.
        expect(routerMock.replace).toHaveBeenCalledExactlyOnceWith({
            url: "/b+r?ref=home#vintage",
            preserveState: true,
            preserveScroll: true
        });
        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it("ignores an id that is not a tab", () => {
        visit("/b+r#legacy");
        const [{ active, select }] = withSetup(() => useHashTabs(IDS));

        select("modern");

        expect(active.value).toBe("legacy");
        expect(routerMock.replace).not.toHaveBeenCalled();
    });
});

describe("useHashTabs — editing the hash", () => {
    it("follows a hash edited by hand", () => {
        visit("/b+r");
        const [{ active }] = withSetup(() => useHashTabs(IDS));

        editHash("vintage");

        expect(active.value).toBe("vintage");
    });

    it("stays put when the edited hash names no tab", () => {
        visit("/b+r#legacy");
        const [{ active }] = withSetup(() => useHashTabs(IDS));

        editHash("modern");

        expect(active.value).toBe("legacy");
    });

    it("stops listening once unmounted", () => {
        visit("/b+r");
        const [{ active }, app] = withSetup(() => useHashTabs(IDS));
        app.unmount();

        editHash("vintage");

        expect(active.value).toBe("commander");
    });

    it("reads the tab ids live, so a tab added later can be opened", () => {
        visit("/b+r");
        const ids = ref(["commander"]);
        const [{ active }] = withSetup(() => useHashTabs(ids));

        ids.value = [...ids.value, "pauper"];
        editHash("pauper");

        expect(active.value).toBe("pauper");
    });
});
