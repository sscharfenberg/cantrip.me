import { describe, expect, it, vi } from "vitest";
import { usePersistedAccordion } from "../usePersistedAccordion.ts";

const KEY = "cantrip:accordion:deck-stats";

describe("usePersistedAccordion — initialOpen", () => {
    it("opens by default on a first visit", () => {
        expect(usePersistedAccordion(KEY).initialOpen).toBe(true);
    });

    it("honours an explicit default when nothing is stored", () => {
        expect(usePersistedAccordion(KEY, false).initialOpen).toBe(false);
    });

    it("reads the user's last choice back", () => {
        localStorage.setItem(KEY, "false");

        expect(usePersistedAccordion(KEY).initialOpen).toBe(false);
    });

    it("prefers the stored choice over the default in both directions", () => {
        localStorage.setItem(KEY, "true");

        expect(usePersistedAccordion(KEY, false).initialOpen).toBe(true);
    });

    it('treats any non-"true" stored value as closed', () => {
        // The value is written by `String(isOpen)`, so anything else is a stale
        // or hand-edited entry; closed is the safe reading.
        localStorage.setItem(KEY, "yes");

        expect(usePersistedAccordion(KEY).initialOpen).toBe(false);
    });

    it("keys each accordion separately", () => {
        localStorage.setItem(KEY, "false");

        expect(usePersistedAccordion(KEY).initialOpen).toBe(false);
        expect(usePersistedAccordion("cantrip:accordion:deck-legality").initialOpen).toBe(true);
    });

    it("falls back to the default when storage cannot be read", () => {
        localStorage.setItem(KEY, "false");
        vi.spyOn(localStorage, "getItem").mockImplementation(() => {
            throw new Error("SecurityError");
        });

        expect(usePersistedAccordion(KEY).initialOpen).toBe(true);
    });

    it("falls back to the default when there is no storage object at all", () => {
        // A sandboxed iframe can leave the global itself undefined. The
        // surrounding try/catch would cover this too — the `typeof` guard just
        // makes it explicit rather than incidental.
        vi.stubGlobal("localStorage", undefined);

        expect(usePersistedAccordion(KEY).initialOpen).toBe(true);
        expect(usePersistedAccordion(KEY, false).initialOpen).toBe(false);
    });
});

describe("usePersistedAccordion — onToggle", () => {
    it("persists both states", () => {
        const { onToggle } = usePersistedAccordion(KEY);

        onToggle(false);
        expect(localStorage.getItem(KEY)).toBe("false");

        onToggle(true);
        expect(localStorage.getItem(KEY)).toBe("true");
    });

    it("round-trips through a fresh call, which is how a reload sees it", () => {
        usePersistedAccordion(KEY).onToggle(false);

        expect(usePersistedAccordion(KEY).initialOpen).toBe(false);
    });

    it("does not throw when storage refuses the write", () => {
        const { onToggle } = usePersistedAccordion(KEY);
        vi.spyOn(localStorage, "setItem").mockImplementation(() => {
            throw new Error("QuotaExceededError");
        });

        expect(() => onToggle(false)).not.toThrow();
        expect(localStorage.getItem(KEY)).toBeNull();
    });

    it("does not throw when there is no storage object at all", () => {
        const { onToggle } = usePersistedAccordion(KEY);
        vi.stubGlobal("localStorage", undefined);

        expect(() => onToggle(false)).not.toThrow();
    });
});
