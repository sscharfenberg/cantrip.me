import { beforeEach, describe, expect, it, vi } from "vitest";
import { setPageProps } from "@/test/inertia.ts";
import type { DeckView } from "../useDeckView.ts";
import { clearAllDeckViewOverrides, useDeckView } from "../useDeckView.ts";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const KEY = (deckId: string): string => `cantrip:deck-view:${deckId}`;

/** Set the signed-in user's stored default, as `HandleInertiaRequests` ships it. */
const signIn = (deckViewDefault: DeckView | null): void => {
    setPageProps({ auth: { user: deckViewDefault === null ? null : { deck_view_default: deckViewDefault } } });
};

beforeEach(() => {
    signIn("text");
    // Refs are cached per deck id at module level; without this the first test
    // to run would fix every later test's starting value.
    clearAllDeckViewOverrides();
});

describe("useDeckView — initial value", () => {
    it("uses the user's saved default when the deck has no override", () => {
        signIn("cards");

        expect(useDeckView("deck-1").viewMode.value).toBe("cards");
    });

    it("prefers a per-deck override over the user default", () => {
        signIn("text");
        localStorage.setItem(KEY("deck-1"), "cards");

        expect(useDeckView("deck-1").viewMode.value).toBe("cards");
    });

    it("falls back to the text view for an unauthenticated visitor", () => {
        signIn(null);

        expect(useDeckView("deck-1").viewMode.value).toBe("text");
    });

    it("ignores a stored value that is not a known view mode", () => {
        signIn("text");
        localStorage.setItem(KEY("deck-1"), "gallery");

        expect(useDeckView("deck-1").viewMode.value).toBe("text");
    });

    it("keeps each deck's override to itself", () => {
        // A key-building bug would make deck-2 inherit deck-1's choice.
        localStorage.setItem(KEY("deck-1"), "cards");

        expect(useDeckView("deck-1").viewMode.value).toBe("cards");
        expect(useDeckView("deck-2").viewMode.value).toBe("text");
    });

    it("falls back to the user default when storage cannot be read", () => {
        signIn("cards");
        localStorage.setItem(KEY("deck-1"), "text");
        vi.spyOn(localStorage, "getItem").mockImplementation(() => {
            throw new Error("SecurityError");
        });

        expect(useDeckView("deck-1").viewMode.value).toBe("cards");
    });
});

describe("useDeckView — setViewMode", () => {
    it("updates the reactive value and persists it", () => {
        const { viewMode, setViewMode } = useDeckView("deck-1");

        setViewMode("cards");

        expect(viewMode.value).toBe("cards");
        expect(localStorage.getItem(KEY("deck-1"))).toBe("cards");
    });

    it("still switches the view when persisting fails", () => {
        const { viewMode, setViewMode } = useDeckView("deck-1");
        vi.spyOn(localStorage, "setItem").mockImplementation(() => {
            throw new Error("QuotaExceededError");
        });

        expect(() => setViewMode("cards")).not.toThrow();
        expect(viewMode.value).toBe("cards");
        expect(localStorage.getItem(KEY("deck-1"))).toBeNull();
    });
});

describe("useDeckView — shared instances", () => {
    it("hands the same ref to every caller for one deck", () => {
        // DeckPage and the nested view switcher must not drift apart.
        const page = useDeckView("deck-1");
        const switcher = useDeckView("deck-1");

        switcher.setViewMode("cards");

        expect(page.viewMode.value).toBe("cards");
        expect(page.viewMode).toBe(switcher.viewMode);
    });

    it("keeps different decks on separate refs", () => {
        const first = useDeckView("deck-1");
        const second = useDeckView("deck-2");

        first.setViewMode("cards");

        expect(second.viewMode.value).toBe("text");
    });
});

describe("clearAllDeckViewOverrides", () => {
    it("removes every per-deck override", () => {
        localStorage.setItem(KEY("deck-1"), "cards");
        localStorage.setItem(KEY("deck-2"), "cards");

        clearAllDeckViewOverrides();

        expect(localStorage.getItem(KEY("deck-1"))).toBeNull();
        expect(localStorage.getItem(KEY("deck-2"))).toBeNull();
    });

    it("leaves the sibling deck-sort overrides alone", () => {
        // The two prefixes differ by one word; a sloppy prefix check would
        // wipe the user's sort preferences along with their view ones.
        localStorage.setItem("cantrip:deck-sort:deck-1", "name");

        clearAllDeckViewOverrides();

        expect(localStorage.getItem("cantrip:deck-sort:deck-1")).toBe("name");
    });

    it("drops the cached refs so the next call re-reads the user default", () => {
        signIn("text");
        const before = useDeckView("deck-1");
        before.setViewMode("cards");

        clearAllDeckViewOverrides();

        const after = useDeckView("deck-1");
        expect(after.viewMode.value).toBe("text");
        expect(after.viewMode).not.toBe(before.viewMode);
    });

    it("does not throw when storage is unreachable", () => {
        localStorage.setItem(KEY("deck-1"), "cards");
        vi.spyOn(localStorage, "key").mockImplementation(() => {
            throw new Error("SecurityError");
        });

        expect(() => clearAllDeckViewOverrides()).not.toThrow();
        expect(localStorage.getItem(KEY("deck-1"))).toBe("cards");
    });
});
