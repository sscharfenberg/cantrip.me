import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { usePasswordEntropy } from "../usePasswordEntropy.ts";

let http: FetchMock;

beforeEach(() => {
    vi.useFakeTimers();
    http = installFetchMock();
    http.json("/api/auth/entropy", { score: 3 });
});

afterEach(() => {
    vi.useRealTimers();
});

/** Advance timers and let the fetch promise chain settle. */
const tick = (ms: number) => vi.advanceTimersByTimeAsync(ms);

describe("usePasswordEntropy — initial state", () => {
    it("starts blank with no score", () => {
        const entropy = usePasswordEntropy();

        expect(entropy.password.value).toBe("");
        expect(entropy.score.value).toBeNull();
    });

    it("gives each caller its own state, so two password fields don't share a score", () => {
        const registration = usePasswordEntropy();
        const confirmation = usePasswordEntropy();

        registration.password.value = "hunter2";

        expect(confirmation.password.value).toBe("");
    });
});

describe("usePasswordEntropy — debouncing", () => {
    it("waits 750ms after the last keystroke before asking the server", async () => {
        const { password, onPasswordChange } = usePasswordEntropy();
        password.value = "correct horse";

        onPasswordChange();
        await tick(749);
        expect(http.calls).toHaveLength(0);

        await tick(1);
        expect(http.calls).toHaveLength(1);
    });

    it("collapses a burst of keystrokes into one request", async () => {
        const { password, onPasswordChange } = usePasswordEntropy();

        for (const value of ["c", "co", "cor", "corr"]) {
            password.value = value;
            onPasswordChange();
            await tick(700);
        }
        await tick(750);

        expect(http.calls).toHaveLength(1);
    });

    it("sends the value as it stood when the timer fired", async () => {
        const { password, onPasswordChange } = usePasswordEntropy();

        password.value = "c";
        onPasswordChange();
        password.value = "correct horse";
        onPasswordChange();
        await tick(750);

        expect(http.lastCall()?.body).toEqual({ p: "correct horse" });
    });

    it("fires anyway after 5 seconds of continuous typing", async () => {
        // Without the max-wait timer a user typing steadily would never see a
        // strength reading at all.
        const { password, onPasswordChange } = usePasswordEntropy();
        password.value = "correct horse";

        for (let elapsed = 0; elapsed < 5000; elapsed += 500) {
            onPasswordChange();
            await tick(500);
        }

        expect(http.calls).toHaveLength(1);
    });

    it("sends only one request when the max-wait wins the race", async () => {
        // The two timers race, and whichever fires first has to disarm the
        // other — otherwise the debounce armed by the last keystroke fires
        // shortly after the max-wait and doubles the request.
        const { password, onPasswordChange } = usePasswordEntropy();
        password.value = "correct horse";

        for (let elapsed = 0; elapsed < 5000; elapsed += 500) {
            onPasswordChange();
            await tick(500);
        }
        await tick(1000);

        expect(http.calls).toHaveLength(1);
    });

    it("starts a fresh max-wait window after each request", async () => {
        const { password, onPasswordChange } = usePasswordEntropy();
        password.value = "correct horse";
        onPasswordChange();
        await tick(750);

        password.value = "correct horse battery";
        for (let elapsed = 0; elapsed < 5000; elapsed += 500) {
            onPasswordChange();
            await tick(500);
        }
        await tick(1000);

        expect(http.calls).toHaveLength(2);
    });
});

describe("usePasswordEntropy — the request", () => {
    it("posts the password to the entropy endpoint as JSON", async () => {
        const { password, onPasswordChange } = usePasswordEntropy();
        password.value = "correct horse";

        onPasswordChange();
        await tick(750);

        expect(http.lastCall()).toMatchObject({
            url: "/api/auth/entropy",
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: { p: "correct horse" }
        });
    });

    it("never sends an empty password", async () => {
        const { onPasswordChange } = usePasswordEntropy();

        onPasswordChange();
        await tick(5000);

        expect(http.calls).toHaveLength(0);
    });

    it("stores the score the server returns", async () => {
        const { password, score, onPasswordChange } = usePasswordEntropy();
        password.value = "correct horse";

        onPasswordChange();
        await tick(750);

        expect(score.value).toBe(3);
    });

    it("keeps a zero score, rather than treating it as absent", async () => {
        // Score 0 is zxcvbn's "very weak" — the most important one to show.
        http.json("/api/auth/entropy", { score: 0 });
        const { password, score, onPasswordChange } = usePasswordEntropy();
        password.value = "password";

        onPasswordChange();
        await tick(750);

        expect(score.value).toBe(0);
    });
});

describe("usePasswordEntropy — failures", () => {
    it("keeps the previous score and logs when the server errors", async () => {
        const error = vi.spyOn(console, "error").mockImplementation(() => {});
        const { password, score, onPasswordChange } = usePasswordEntropy();
        password.value = "correct horse";
        onPasswordChange();
        await tick(750);

        http.status("/api/auth/entropy", 500);
        password.value = "correct horse battery";
        onPasswordChange();
        await tick(750);

        expect(score.value).toBe(3);
        expect(error).toHaveBeenCalled();
    });

    it("swallows a dropped connection", async () => {
        const error = vi.spyOn(console, "error").mockImplementation(() => {});
        http.reject("/api/auth/entropy");
        const { password, score, onPasswordChange } = usePasswordEntropy();
        password.value = "correct horse";

        onPasswordChange();
        await tick(750);

        expect(score.value).toBeNull();
        expect(error).toHaveBeenCalled();
    });
});

describe("usePasswordEntropy — reset", () => {
    it("clears the password and the score", async () => {
        const entropy = usePasswordEntropy();
        entropy.password.value = "correct horse";
        entropy.onPasswordChange();
        await tick(750);

        entropy.reset();

        expect(entropy.password.value).toBe("");
        expect(entropy.score.value).toBeNull();
    });

    it("lets a pending debounce fire harmlessly against the cleared password", async () => {
        // `reset` only touches the refs; the timer is left armed. It fires as
        // scheduled, sees an empty password, and the length guard drops it — so
        // no request goes out carrying a stale value.
        const entropy = usePasswordEntropy();
        entropy.password.value = "correct horse";
        entropy.onPasswordChange();

        entropy.reset();
        await tick(750);

        expect(http.calls).toHaveLength(0);
    });
});
