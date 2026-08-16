import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { UseToastReturn } from "../useToast.ts";

/**
 * Toast state lives at module level so a toast raised from a plain `.ts` file
 * lands in the same list a component renders. That also means it survives
 * between tests, so each test gets a freshly evaluated module.
 */
let toast: UseToastReturn;

beforeEach(async () => {
    vi.useFakeTimers();
    vi.resetModules();
    toast = (await import("../useToast.ts")).useToast();
});

afterEach(() => {
    vi.useRealTimers();
});

/** Raise `count` toasts with predictable messages. */
const addMany = (count: number, duration?: number): void => {
    for (let i = 1; i <= count; i++) {
        toast.addToast(`toast ${i}`, "info", duration);
    }
};

const messages = (): string[] => toast.activeToasts.value.map(t => t.message);

describe("useToast — adding", () => {
    it("starts with nothing shown", () => {
        expect(toast.activeToasts.value).toEqual([]);
    });

    it("shows a toast immediately", () => {
        toast.addToast("Saved!");

        expect(toast.activeToasts.value).toHaveLength(1);
        expect(toast.activeToasts.value[0]).toMatchObject({ message: "Saved!", type: "info", duration: 5000 });
    });

    it("gives every toast its own id", () => {
        addMany(3);

        expect(new Set(toast.activeToasts.value.map(t => t.id)).size).toBe(3);
    });

    it("honours an explicit type and duration", () => {
        toast.addToast("Boom", "error", 8000);

        expect(toast.activeToasts.value[0]).toMatchObject({ type: "error", duration: 8000 });
    });

    it("shares one list across every call site", async () => {
        const other = (await import("../useToast.ts")).useToast();

        other.addToast("From elsewhere");

        expect(messages()).toEqual(["From elsewhere"]);
    });
});

describe("useToast — auto dismiss", () => {
    it("removes a toast once its duration elapses", () => {
        toast.addToast("Saved!", "info", 3000);

        vi.advanceTimersByTime(2999);
        expect(toast.activeToasts.value).toHaveLength(1);

        vi.advanceTimersByTime(1);
        expect(toast.activeToasts.value).toEqual([]);
    });

    it("keeps a zero-duration toast until it is dismissed by hand", () => {
        toast.addToast("Stays until closed", "warning", 0);

        vi.advanceTimersByTime(60_000);

        expect(toast.activeToasts.value).toHaveLength(1);
    });

    it("times each toast independently", () => {
        toast.addToast("short", "info", 1000);
        toast.addToast("long", "info", 5000);

        vi.advanceTimersByTime(1000);

        expect(messages()).toEqual(["long"]);
    });
});

describe("useToast — removal", () => {
    it("removes the named toast and leaves the rest", () => {
        addMany(3);
        const [, second] = toast.activeToasts.value;

        toast.removeToast(second.id);

        expect(messages()).toEqual(["toast 1", "toast 3"]);
    });

    it("ignores an unknown id", () => {
        toast.addToast("Saved!");

        expect(() => toast.removeToast("no-such-toast")).not.toThrow();
        expect(toast.activeToasts.value).toHaveLength(1);
    });

    it("cancels the pending timer, so it cannot promote a second queued toast", () => {
        // Four toasts that never expire, one that would in 3s, two queued
        // behind them. Dismissing the timed one by hand frees exactly one slot.
        // If its timer were left armed it would fire later and re-enter the
        // promotion branch with nothing freed, pushing the active list to six
        // and breaking the documented five-at-once limit.
        addMany(4, 0);
        toast.addToast("dismissed", "info", 3000);
        toast.addToast("queued A", "info", 0);
        toast.addToast("queued B", "info", 0);
        const dismissed = toast.activeToasts.value.find(t => t.message === "dismissed")!;

        toast.removeToast(dismissed.id);
        expect(messages()).toContain("queued A");
        expect(toast.activeToasts.value).toHaveLength(5);

        vi.advanceTimersByTime(10_000);

        expect(toast.activeToasts.value).toHaveLength(5);
        expect(messages()).not.toContain("queued B");
    });
});

describe("useToast — queueing", () => {
    it("shows at most five at once and queues the rest", () => {
        addMany(7, 0);

        expect(messages()).toEqual(["toast 1", "toast 2", "toast 3", "toast 4", "toast 5"]);
    });

    it("promotes the next queued toast when a slot frees up", () => {
        addMany(6, 0);

        toast.removeToast(toast.activeToasts.value[0].id);

        expect(messages()).toEqual(["toast 2", "toast 3", "toast 4", "toast 5", "toast 6"]);
    });

    it("only starts a queued toast's timer once it becomes visible", () => {
        // Five long-lived toasts, then a short one behind them. The short one
        // must not expire while it is still waiting in the queue.
        addMany(5, 10_000);
        toast.addToast("queued", "info", 1000);

        vi.advanceTimersByTime(9999);
        expect(messages()).not.toContain("queued");

        vi.advanceTimersByTime(1);
        expect(messages()).toContain("queued");

        vi.advanceTimersByTime(1000);
        expect(messages()).not.toContain("queued");
    });

    it("drains the queue in order as slots free up", () => {
        addMany(7, 0);
        const active = [...toast.activeToasts.value];

        toast.removeToast(active[0].id);
        toast.removeToast(active[1].id);

        expect(messages()).toEqual(["toast 3", "toast 4", "toast 5", "toast 6", "toast 7"]);
    });
});
