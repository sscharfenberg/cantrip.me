import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useClipboard } from "../useClipboard.ts";

/** Install a working `navigator.clipboard`, returning the write spy. */
const withClipboardApi = () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal("navigator", { ...navigator, clipboard: { writeText } });
    return writeText;
};

/** Remove `navigator.clipboard`, forcing the legacy execCommand path. */
const withoutClipboardApi = (): void => {
    vi.stubGlobal("navigator", { ...navigator, clipboard: undefined });
};

/**
 * Install a `document.execCommand`.
 *
 * jsdom does not implement it at all, so `vi.spyOn` has nothing to wrap and a
 * raw `defineProperty` would outlive the test — neither `restoreMocks` nor
 * `unstubGlobals` reaches one. The descriptor is therefore removed again in an
 * `afterEach`.
 */
const withExecCommand = (implementation: () => boolean): ReturnType<typeof vi.fn> => {
    const execCommand = vi.fn(implementation);
    Object.defineProperty(document, "execCommand", { value: execCommand, configurable: true });
    return execCommand;
};

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    Reflect.deleteProperty(document, "execCommand");
});

describe("useClipboard — modern API", () => {
    it("starts with no copied feedback showing", () => {
        expect(useClipboard().copied.value).toBe(false);
    });

    it("writes through navigator.clipboard when it is available", async () => {
        const writeText = withClipboardApi();
        const { copy } = useClipboard();

        await copy("Sol Ring");

        expect(writeText).toHaveBeenCalledExactlyOnceWith("Sol Ring");
    });

    it("raises the copied flag on success", async () => {
        withClipboardApi();
        const { copied, copy } = useClipboard();

        await copy("Sol Ring");

        expect(copied.value).toBe(true);
    });

    it("lowers the copied flag again after two seconds", async () => {
        withClipboardApi();
        const { copied, copy } = useClipboard();
        await copy("Sol Ring");

        vi.advanceTimersByTime(1999);
        expect(copied.value).toBe(true);

        vi.advanceTimersByTime(1);
        expect(copied.value).toBe(false);
    });

    it("gives every consumer its own flag, so two copy buttons stay independent", async () => {
        withClipboardApi();
        const deckList = useClipboard();
        const deckLink = useClipboard();

        await deckList.copy("Sol Ring");

        expect(deckList.copied.value).toBe(true);
        expect(deckLink.copied.value).toBe(false);
    });
});

describe("useClipboard — legacy fallback", () => {
    it("falls back to execCommand when the Clipboard API is missing", async () => {
        // iOS WebViews and non-secure contexts.
        withoutClipboardApi();
        const execCommand = withExecCommand(() => true);
        const { copied, copy } = useClipboard();

        await copy("Sol Ring");

        expect(execCommand).toHaveBeenCalledExactlyOnceWith("copy");
        expect(copied.value).toBe(true);
    });

    it("leaves no scratch element behind in the document", async () => {
        withoutClipboardApi();
        withExecCommand(() => true);
        const { copy } = useClipboard();

        await copy("Sol Ring");

        expect(document.querySelectorAll("textarea")).toHaveLength(0);
    });

    it("reports success even when the browser quietly refuses the copy", async () => {
        // `execCommand` returning false is the commonest real failure, and the
        // composable does not distinguish it — pinned so the behaviour is a
        // decision rather than an oversight.
        withoutClipboardApi();
        withExecCommand(() => false);
        const { copied, copy } = useClipboard();

        await copy("Sol Ring");

        expect(copied.value).toBe(true);
    });

    it("keeps the scratch element off-screen and holding the text while it exists", async () => {
        withoutClipboardApi();
        let snapshot: { position: string; opacity: string; value: string } | null = null;
        withExecCommand(() => {
            const scratch = document.querySelector("textarea");
            snapshot = scratch
                ? { position: scratch.style.position, opacity: scratch.style.opacity, value: scratch.value }
                : null;
            return true;
        });

        await useClipboard().copy("Sol Ring");

        // Off-screen and invisible: the copy must not flash a box at the user.
        expect(snapshot).toEqual({ position: "fixed", opacity: "0", value: "Sol Ring" });
    });
});

describe("useClipboard — failures", () => {
    it("swallows a rejected clipboard write rather than breaking the UI", async () => {
        // Browser or OS permission policy — nothing the app can do about it.
        vi.stubGlobal("navigator", {
            ...navigator,
            clipboard: { writeText: vi.fn().mockRejectedValue(new Error("NotAllowedError")) }
        });
        const { copied, copy } = useClipboard();

        await expect(copy("Sol Ring")).resolves.toBeUndefined();
        expect(copied.value).toBe(false);
    });

    it("swallows a throwing execCommand too", async () => {
        withoutClipboardApi();
        withExecCommand(() => {
            throw new Error("unsupported");
        });
        const { copied, copy } = useClipboard();

        await expect(copy("Sol Ring")).resolves.toBeUndefined();
        expect(copied.value).toBe(false);
    });

    it("cleans up the scratch element even when execCommand throws", async () => {
        // Without the `finally` the off-screen textarea would stay in the
        // document for the rest of the session, once per failed copy.
        withoutClipboardApi();
        withExecCommand(() => {
            throw new Error("unsupported");
        });

        await useClipboard().copy("Sol Ring");

        expect(document.querySelectorAll("textarea")).toHaveLength(0);
    });
});
