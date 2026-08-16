import { beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import { useLogin } from "../useLogin.ts";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

let http: FetchMock;

beforeEach(() => {
    setPageProps({ csrfToken: "csrf-token" });
    http = installFetchMock();
});

/** Fill the credential fields, as the form's v-model bindings would. */
const withCredentials = () => {
    const login = useLogin();
    login.name.value = "planeswalker";
    login.password.value = "correct horse battery staple";
    return login;
};

describe("useLogin — initial state", () => {
    it("starts empty, idle and not challenged", () => {
        const login = useLogin();

        expect(login.name.value).toBe("");
        expect(login.password.value).toBe("");
        expect(login.errors.value).toEqual({});
        expect(login.processing.value).toBe(false);
        expect(login.requiresTwoFactor.value).toBe(false);
        expect(login.showRecoveryCode.value).toBe(false);
    });

    it("ticks 'remember me' by default", () => {
        expect(useLogin().remember.value).toBe(true);
    });

    it("gives each caller its own state", () => {
        const first = useLogin();
        const second = useLogin();

        first.name.value = "planeswalker";

        expect(second.name.value).toBe("");
    });
});

describe("useLogin — password step", () => {
    it("posts the credentials as JSON to Fortify", async () => {
        const login = withCredentials();

        await login.submit();

        const call = http.lastCall("/login");
        expect(call?.method).toBe("POST");
        expect(call?.body).toEqual({
            name: "planeswalker",
            password: "correct horse battery staple",
            remember: true
        });
    });

    it("asks for JSON explicitly, which is what makes Fortify report the 2FA step", async () => {
        // Without these headers Fortify redirects to its own two-factor page
        // instead of returning `{ two_factor: true }`, and the challenge could
        // not stay on this page.
        await withCredentials().submit();

        expect(http.lastCall("/login")?.headers).toMatchObject({
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": "csrf-token"
        });
    });

    it("follows the redirect the server returns", async () => {
        http.json("/login", { redirect: "/decks" });

        await withCredentials().submit();

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/decks");
    });

    it("falls back to the dashboard when the server names no redirect", async () => {
        http.json("/login", {});

        await withCredentials().submit();

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/dashboard");
    });

    it("survives a success response that is not JSON at all", async () => {
        http.malformed("/login");

        await withCredentials().submit();

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/dashboard");
    });

    it("clears the processing flag once done", async () => {
        http.json("/login", { two_factor: true });
        const login = withCredentials();

        await login.submit();

        expect(login.processing.value).toBe(false);
    });
});

describe("useLogin — validation errors", () => {
    it("flattens Fortify's per-field error arrays to one message each", async () => {
        http.json("/login", { errors: { name: ["These credentials do not match.", "second"] } }, 422);
        const login = withCredentials();

        await login.submit();

        expect(login.errors.value).toEqual({ name: "These credentials do not match." });
    });

    it("accepts a bare string as well as an array", async () => {
        http.json("/login", { errors: { name: "These credentials do not match." } }, 422);
        const login = withCredentials();

        await login.submit();

        expect(login.errors.value).toEqual({ name: "These credentials do not match." });
    });

    it("does not navigate on a validation failure", async () => {
        http.json("/login", { errors: { name: ["nope"] } }, 422);

        await withCredentials().submit();

        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it("clears stale errors before the next attempt", async () => {
        http.json("/login", { errors: { name: ["nope"] } }, 422);
        const login = withCredentials();
        await login.submit();

        http.json("/login", { redirect: "/decks" });
        await login.submit();

        expect(login.errors.value).toEqual({});
    });

    it("stops on a non-422 failure without navigating", async () => {
        http.status("/login", 500);
        const login = withCredentials();

        await login.submit();

        expect(routerMock.visit).not.toHaveBeenCalled();
        expect(login.processing.value).toBe(false);
    });
});

describe("useLogin — two-factor challenge", () => {
    /** Get past the password step into the challenge state. */
    const challenged = async () => {
        http.json("/login", { two_factor: true });
        const login = withCredentials();
        await login.submit();
        return login;
    };

    it("switches to the challenge instead of navigating", async () => {
        const login = await challenged();

        expect(login.requiresTwoFactor.value).toBe(true);
        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it("drops the password from memory once it has been accepted", async () => {
        const login = await challenged();

        expect(login.password.value).toBe("");
    });

    it("sends the authenticator code to the challenge endpoint", async () => {
        const login = await challenged();
        login.recoveryCode.value = "123456";

        await login.submit();

        expect(http.lastCall("/two-factor-challenge")?.body).toEqual({ code: "123456" });
    });

    it("sends the same input as a recovery code when the user switches mode", async () => {
        const login = await challenged();
        login.showRecoveryCode.value = true;
        login.recoveryCode.value = "abcdef-123456";

        await login.submit();

        expect(http.lastCall("/two-factor-challenge")?.body).toEqual({ recovery_code: "abcdef-123456" });
    });

    it("does not re-post the credentials once challenged", async () => {
        const login = await challenged();

        await login.submit();

        expect(http.callsTo("/login")).toHaveLength(1);
    });

    it("follows the redirect on a correct code", async () => {
        const login = await challenged();
        http.json("/two-factor-challenge", { redirect: "/decks" });

        await login.submit();

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/decks");
    });

    it("falls back to the dashboard when the challenge names no redirect", async () => {
        const login = await challenged();
        http.json("/two-factor-challenge", {});

        await login.submit();

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/dashboard");
    });

    it("surfaces a wrong code as an inline error and stays on the challenge", async () => {
        const login = await challenged();
        http.json("/two-factor-challenge", { errors: { code: ["The provided code was invalid."] } }, 422);

        await login.submit();

        expect(login.errors.value).toEqual({ code: "The provided code was invalid." });
        expect(login.requiresTwoFactor.value).toBe(true);
        expect(routerMock.visit).not.toHaveBeenCalled();
        expect(login.processing.value).toBe(false);
    });

    it("surfaces a wrong recovery code under its own field", async () => {
        const login = await challenged();
        login.showRecoveryCode.value = true;
        http.json("/two-factor-challenge", { errors: { recovery_code: ["That code has been used."] } }, 422);

        await login.submit();

        expect(login.errors.value).toEqual({ recovery_code: "That code has been used." });
    });

    it("stops on a server error without navigating", async () => {
        const login = await challenged();
        http.status("/two-factor-challenge", 500);

        await login.submit();

        expect(routerMock.visit).not.toHaveBeenCalled();
        expect(login.processing.value).toBe(false);
    });
});
