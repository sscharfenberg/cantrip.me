import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type * as RecentlyAdded from "../useRecentlyAdded.ts";

/**
 * The highlighted-id ref lives at module level so the quick-add control and the
 * card list see one signal without prop drilling — which means it survives
 * between tests. Each test therefore re-imports a freshly evaluated module.
 */
let recentlyAdded: typeof RecentlyAdded;

beforeEach(async () => {
    vi.useFakeTimers();
    vi.resetModules();
    recentlyAdded = await import("../useRecentlyAdded.ts");
});

afterEach(() => {
    vi.useRealTimers();
});

describe("useRecentlyAddedId", () => {
    it("starts with nothing highlighted", () => {
        expect(recentlyAdded.useRecentlyAddedId().value).toBeNull();
    });

    it("hands every caller the same signal", () => {
        const list = recentlyAdded.useRecentlyAddedId();
        const quickAdd = recentlyAdded.useRecentlyAddedId();

        recentlyAdded.markRecentlyAdded("oracle-1");

        expect(list.value).toBe("oracle-1");
        expect(quickAdd.value).toBe("oracle-1");
    });
});

describe("markRecentlyAdded", () => {
    it("highlights the given oracle id", () => {
        const highlighted = recentlyAdded.useRecentlyAddedId();

        recentlyAdded.markRecentlyAdded("oracle-1");

        expect(highlighted.value).toBe("oracle-1");
    });

    it("clears the highlight after 1.5 seconds", () => {
        const highlighted = recentlyAdded.useRecentlyAddedId();
        recentlyAdded.markRecentlyAdded("oracle-1");

        vi.advanceTimersByTime(1499);
        expect(highlighted.value).toBe("oracle-1");

        vi.advanceTimersByTime(1);
        expect(highlighted.value).toBeNull();
    });

    it("restarts the countdown when a second card is added", () => {
        // Otherwise the first card's timer would cut the second card's
        // highlight short.
        const highlighted = recentlyAdded.useRecentlyAddedId();
        recentlyAdded.markRecentlyAdded("oracle-1");

        vi.advanceTimersByTime(1400);
        recentlyAdded.markRecentlyAdded("oracle-2");

        vi.advanceTimersByTime(1400);
        expect(highlighted.value).toBe("oracle-2");

        vi.advanceTimersByTime(100);
        expect(highlighted.value).toBeNull();
    });

    it("exposes a read-only ref, so only markRecentlyAdded can move the highlight", () => {
        const highlighted = recentlyAdded.useRecentlyAddedId() as { value: string | null };

        // Vue's `readonly()` warns and ignores the write rather than throwing.
        const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
        highlighted.value = "oracle-forced";

        expect(highlighted.value).toBeNull();
        expect(warn).toHaveBeenCalled();
    });
});
