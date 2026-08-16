import { beforeEach, describe, expect, it, vi } from "vitest";
import { setPageProps } from "@/test/inertia.ts";
import type { DeckSort } from "../useDeckSort.ts";
import { clearAllDeckSortOverrides, useDeckSort } from "../useDeckSort.ts";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const KEY = (deckId: string): string => `cantrip:deck-sort:${deckId}`;

/** Set the signed-in user's stored default, as `HandleInertiaRequests` ships it. */
const signIn = (deckSortDefault: DeckSort | null): void => {
    setPageProps({ auth: { user: deckSortDefault === null ? null : { deck_sort_default: deckSortDefault } } });
};

beforeEach(() => {
    signIn("mana");
    // The composable caches a ref per deck id at module level, so without this
    // the first test to run would decide every later test's starting value.
    clearAllDeckSortOverrides();
});

describe("useDeckSort — initial value", () => {
    it("uses the user's saved default when the deck has no override", () => {
        signIn("name");

        expect(useDeckSort("deck-1").sortMode.value).toBe("name");
    });

    it("prefers a per-deck override over the user default", () => {
        signIn("mana");
        localStorage.setItem(KEY("deck-1"), "name");

        expect(useDeckSort("deck-1").sortMode.value).toBe("name");
    });

    it("falls back to mana for an unauthenticated visitor", () => {
        signIn(null);

        expect(useDeckSort("deck-1").sortMode.value).toBe("mana");
    });

    it("ignores a stored value that is not a known sort mode", () => {
        // Stale key from an older release, or a hand-edited storage entry.
        signIn("mana");
        localStorage.setItem(KEY("deck-1"), "by-colour");

        expect(useDeckSort("deck-1").sortMode.value).toBe("mana");
    });

    it("keeps each deck's override to itself", () => {
        localStorage.setItem(KEY("deck-1"), "name");

        expect(useDeckSort("deck-1").sortMode.value).toBe("name");
        expect(useDeckSort("deck-2").sortMode.value).toBe("mana");
    });

    it("falls back to the user default when storage cannot be read", () => {
        // Safari private mode, sandboxed iframe, disabled storage. The stored
        // override is deliberately different from the user default, so a read
        // that silently succeeded would fail this assertion.
        signIn("name");
        localStorage.setItem(KEY("deck-1"), "mana");
        vi.spyOn(localStorage, "getItem").mockImplementation(() => {
            throw new Error("SecurityError");
        });

        expect(useDeckSort("deck-1").sortMode.value).toBe("name");
    });
});

describe("useDeckSort — setSortMode", () => {
    it("updates the reactive value", () => {
        const { sortMode, setSortMode } = useDeckSort("deck-1");

        setSortMode("name");

        expect(sortMode.value).toBe("name");
    });

    it("persists the choice as a per-deck override", () => {
        useDeckSort("deck-1").setSortMode("name");

        expect(localStorage.getItem(KEY("deck-1"))).toBe("name");
    });

    it("still switches the sort when persisting fails", () => {
        const { sortMode, setSortMode } = useDeckSort("deck-1");
        vi.spyOn(localStorage, "setItem").mockImplementation(() => {
            throw new Error("QuotaExceededError");
        });

        expect(() => setSortMode("name")).not.toThrow();
        expect(sortMode.value).toBe("name");
        // Nothing was written — the sort works, it just doesn't survive a reload.
        expect(localStorage.getItem(KEY("deck-1"))).toBeNull();
    });
});

describe("useDeckSort — shared instances", () => {
    it("hands the same ref to every caller for one deck", () => {
        // DeckPage and the nested sort dropdown must not drift apart.
        const page = useDeckSort("deck-1");
        const dropdown = useDeckSort("deck-1");

        dropdown.setSortMode("name");

        expect(page.sortMode.value).toBe("name");
        expect(page.sortMode).toBe(dropdown.sortMode);
    });

    it("keeps different decks on separate refs", () => {
        const first = useDeckSort("deck-1");
        const second = useDeckSort("deck-2");

        first.setSortMode("name");

        expect(second.sortMode.value).toBe("mana");
    });
});

describe("clearAllDeckSortOverrides", () => {
    it("removes every per-deck override", () => {
        localStorage.setItem(KEY("deck-1"), "name");
        localStorage.setItem(KEY("deck-2"), "name");

        clearAllDeckSortOverrides();

        expect(localStorage.getItem(KEY("deck-1"))).toBeNull();
        expect(localStorage.getItem(KEY("deck-2"))).toBeNull();
    });

    it("leaves unrelated storage keys alone", () => {
        localStorage.setItem("cantrip:deck-view:deck-1", "cards");
        localStorage.setItem("theme", "dark");

        clearAllDeckSortOverrides();

        expect(localStorage.getItem("cantrip:deck-view:deck-1")).toBe("cards");
        expect(localStorage.getItem("theme")).toBe("dark");
    });

    it("drops the cached refs so the next call re-reads the user default", () => {
        signIn("mana");
        const before = useDeckSort("deck-1");
        before.setSortMode("name");

        clearAllDeckSortOverrides();

        const after = useDeckSort("deck-1");
        expect(after.sortMode.value).toBe("mana");
        expect(after.sortMode).not.toBe(before.sortMode);
    });

    it("does not throw when storage is unreachable", () => {
        localStorage.setItem(KEY("deck-1"), "name");
        vi.spyOn(localStorage, "key").mockImplementation(() => {
            throw new Error("SecurityError");
        });

        expect(() => clearAllDeckSortOverrides()).not.toThrow();
        // The entry survives, which is what proves the throwing path ran at all.
        expect(localStorage.getItem(KEY("deck-1"))).toBe("name");
    });
});
