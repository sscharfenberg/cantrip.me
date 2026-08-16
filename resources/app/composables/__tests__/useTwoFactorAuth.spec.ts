import { beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import { useTwoFactorAuth } from "../useTwoFactorAuth.ts";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

const QR_CODE = "/user/two-factor-qr-code";
const SECRET_KEY = "/user/two-factor-secret-key";
const RECOVERY_CODES = "/user/two-factor-recovery-codes";
const CONFIRM_PASSWORD = "/confirm-password";
const TWO_FACTOR = "/user/two-factor-authentication";

let http: FetchMock;

/** Shorthand so each test reads `twoFactor()` rather than the full name. */
const twoFactor = useTwoFactorAuth;

/**
 * Blank every ref the composable keeps at module level.
 *
 * `vi.resetModules()` would be the obvious alternative and is the wrong tool
 * here: re-evaluating the composable also re-evaluates the mocked
 * `@inertiajs/vue3`, which hands it a *different* `pageProps` object than the
 * one this file's `setPageProps` writes to. Written out longhand rather than
 * calling `clearTwoFactorAuthData()` so the reset can't inherit a bug from the
 * function it is supposed to be testing.
 */
const resetSharedState = (): void => {
    const auth = useTwoFactorAuth();
    auth.qrCodeSvg.value = null;
    auth.manualSetupKey.value = null;
    auth.recoveryCodesList.value = [];
    auth.errors.value = [];
    auth.validationErrors.value = {};
    auth.isRecoveryCodesVisible.value = false;
    auth.showSetupModal.value = false;
};

/**
 * Set the Inertia shared props this composable reads.
 *
 * `requiresPasswordConfirmation` is the interesting one: it mirrors Fortify's
 * `confirmPassword` config and decides whether every sensitive action is
 * preceded by a password check.
 */
const setFortifyProps = (props: { requiresPasswordConfirmation?: boolean; twoFactorEnabled?: boolean } = {}): void => {
    setPageProps({
        csrfToken: "csrf-token",
        requiresConfirmation: true,
        requiresPasswordConfirmation: props.requiresPasswordConfirmation ?? false,
        twoFactorEnabled: props.twoFactorEnabled ?? false
    });
};

beforeEach(() => {
    resetSharedState();
    setFortifyProps();
    http = installFetchMock();
    http.json(QR_CODE, { svg: "<svg/>", url: "otpauth://totp/…" });
    http.json(SECRET_KEY, { secretKey: "ABCD1234" });
    http.json(RECOVERY_CODES, ["code-1", "code-2"]);
});

/** The options object handed to the last `router.post` / `router.delete` call. */
const lastRouterOptions = (spy: { mock: { calls: unknown[][] } }): Record<string, () => void> => {
    const args = spy.mock.calls[spy.mock.calls.length - 1];
    return args[args.length - 1] as Record<string, () => void>;
};

describe("useTwoFactorAuth — shared state", () => {
    it("starts with nothing loaded", () => {
        const auth = twoFactor();

        expect(auth.qrCodeSvg.value).toBeNull();
        expect(auth.manualSetupKey.value).toBeNull();
        expect(auth.recoveryCodesList.value).toEqual([]);
        expect(auth.errors.value).toEqual([]);
        expect(auth.hasSetupData.value).toBe(false);
    });

    it("shares setup data across every consumer", async () => {
        // The setup modal and the dashboard panel are separate components
        // reading one enrollment.
        const modal = twoFactor();
        const panel = twoFactor();

        await modal.fetchQrCode();

        expect(panel.qrCodeSvg.value).toBe("<svg/>");
    });

    it("keeps the processing flag per consumer, so one form does not disable another", () => {
        const enableForm = twoFactor();
        const disableForm = twoFactor();

        enableForm.processing.value = true;

        expect(disableForm.processing.value).toBe(false);
    });

    it("reads the Fortify flags off the Inertia shared props", () => {
        setFortifyProps({ requiresPasswordConfirmation: true, twoFactorEnabled: true });
        const auth = twoFactor();

        expect(auth.requiresConfirmation.value).toBe(true);
        expect(auth.requiresPasswordConfirmation.value).toBe(true);
        expect(auth.twoFactorEnabled.value).toBe(true);
    });
});

describe("useTwoFactorAuth — loading setup data", () => {
    it("stores the QR code SVG", async () => {
        const auth = twoFactor();

        await auth.fetchQrCode();

        expect(auth.qrCodeSvg.value).toBe("<svg/>");
        expect(http.lastCall(QR_CODE)?.headers).toMatchObject({ Accept: "application/json" });
    });

    it("stores the manual entry key", async () => {
        const auth = twoFactor();

        await auth.fetchSetupKey();

        expect(auth.manualSetupKey.value).toBe("ABCD1234");
    });

    it("records an error and clears the QR code when the request fails", async () => {
        http.status(QR_CODE, 500);
        const auth = twoFactor();

        await auth.fetchQrCode();

        expect(auth.qrCodeSvg.value).toBeNull();
        expect(auth.errors.value).toEqual(["Failed to fetch QR code"]);
    });

    it("records an error and clears the key when that request fails", async () => {
        http.status(SECRET_KEY, 500);
        const auth = twoFactor();

        await auth.fetchSetupKey();

        expect(auth.manualSetupKey.value).toBeNull();
        expect(auth.errors.value).toEqual(["Failed to fetch a setup key"]);
    });

    it("loads both halves in one call", async () => {
        const auth = twoFactor();

        await auth.fetchSetupData();

        expect(auth.qrCodeSvg.value).toBe("<svg/>");
        expect(auth.manualSetupKey.value).toBe("ABCD1234");
        expect(auth.hasSetupData.value).toBe(true);
    });

    it("clears stale errors before reloading", async () => {
        http.status(QR_CODE, 500);
        const auth = twoFactor();
        await auth.fetchQrCode();

        http.json(QR_CODE, { svg: "<svg/>", url: "otpauth://totp/…" });
        await auth.fetchSetupData();

        expect(auth.errors.value).toEqual([]);
    });

    it("reports incomplete setup data when only one half loaded", async () => {
        http.status(SECRET_KEY, 500);
        const auth = twoFactor();

        await auth.fetchSetupData();

        expect(auth.qrCodeSvg.value).toBe("<svg/>");
        expect(auth.hasSetupData.value).toBe(false);
    });
});

describe("useTwoFactorAuth — recovery codes", () => {
    it("stores the codes", async () => {
        const auth = twoFactor();

        await auth.fetchRecoveryCodes();

        expect(auth.recoveryCodesList.value).toEqual(["code-1", "code-2"]);
    });

    it("empties the list and records an error on failure", async () => {
        http.status(RECOVERY_CODES, 500);
        const auth = twoFactor();

        await auth.fetchRecoveryCodes();

        expect(auth.recoveryCodesList.value).toEqual([]);
        expect(auth.errors.value).toEqual(["Failed to fetch recovery codes"]);
    });
});

describe("useTwoFactorAuth — clearing state", () => {
    it("clears the setup data and any errors", async () => {
        const auth = twoFactor();
        await auth.fetchSetupData();
        auth.errors.value.push("stale");

        auth.clearSetupData();

        expect(auth.qrCodeSvg.value).toBeNull();
        expect(auth.manualSetupKey.value).toBeNull();
        expect(auth.errors.value).toEqual([]);
    });

    it("clears errors on their own", () => {
        const auth = twoFactor();
        auth.errors.value.push("stale");

        auth.clearErrors();

        expect(auth.errors.value).toEqual([]);
    });

    it("wipes everything after 2FA is turned off", async () => {
        const auth = twoFactor();
        await auth.fetchSetupData();
        await auth.fetchRecoveryCodes();
        auth.isRecoveryCodesVisible.value = true;
        auth.showSetupModal.value = true;

        auth.clearTwoFactorAuthData();

        expect(auth.qrCodeSvg.value).toBeNull();
        expect(auth.manualSetupKey.value).toBeNull();
        expect(auth.recoveryCodesList.value).toEqual([]);
        expect(auth.isRecoveryCodesVisible.value).toBe(false);
        expect(auth.showSetupModal.value).toBe(false);
    });
});

describe("useTwoFactorAuth — confirmPassword", () => {
    it("posts the password with the CSRF token", async () => {
        await twoFactor().confirmPassword("hunter2");

        expect(http.lastCall(CONFIRM_PASSWORD)).toMatchObject({
            method: "POST",
            headers: { Accept: "application/json", "X-CSRF-TOKEN": "csrf-token" },
            body: { password: "hunter2" }
        });
    });

    it("reports success", async () => {
        await expect(twoFactor().confirmPassword("hunter2")).resolves.toBe(true);
    });

    it("reports failure rather than rejecting when the error body is not JSON", async () => {
        // Every caller treats a rejection as fatal and leaves `processing` on,
        // so a 419 HTML page must still resolve to `false`.
        http.malformed(CONFIRM_PASSWORD, 422);
        const auth = twoFactor();

        await expect(auth.confirmPassword("wrong")).resolves.toBe(false);
        expect(auth.validationErrors.value).toEqual({});
    });

    it("flattens the validation errors and reports failure", async () => {
        http.json(CONFIRM_PASSWORD, { errors: { password: ["The password is incorrect.", "second"] } }, 422);
        const auth = twoFactor();

        await expect(auth.confirmPassword("wrong")).resolves.toBe(false);
        expect(auth.validationErrors.value).toEqual({ password: "The password is incorrect." });
    });

    it("accepts a bare string as well as an array", async () => {
        http.json(CONFIRM_PASSWORD, { errors: { password: "The password is incorrect." } }, 422);
        const auth = twoFactor();

        await auth.confirmPassword("wrong");

        expect(auth.validationErrors.value).toEqual({ password: "The password is incorrect." });
    });
});

describe("useTwoFactorAuth — enableTwoFactor", () => {
    it("skips the password check when Fortify does not require one", async () => {
        await twoFactor().enableTwoFactor("hunter2");

        expect(http.callsTo(CONFIRM_PASSWORD)).toHaveLength(0);
        expect(routerMock.post).toHaveBeenCalledWith(TWO_FACTOR, {}, expect.anything());
    });

    it("confirms the password first when Fortify requires it", async () => {
        setFortifyProps({ requiresPasswordConfirmation: true });

        await twoFactor().enableTwoFactor("hunter2");

        expect(http.callsTo(CONFIRM_PASSWORD)).toHaveLength(1);
        expect(routerMock.post).toHaveBeenCalled();
    });

    it("stops before enabling when the password is wrong", async () => {
        setFortifyProps({ requiresPasswordConfirmation: true });
        http.json(CONFIRM_PASSWORD, { errors: { password: ["nope"] } }, 422);
        const auth = twoFactor();

        await auth.enableTwoFactor("wrong");

        expect(routerMock.post).not.toHaveBeenCalled();
        expect(auth.processing.value).toBe(false);
    });

    it("opens the setup modal once the server accepts", async () => {
        const auth = twoFactor();
        await auth.enableTwoFactor("hunter2");

        lastRouterOptions(routerMock.post).onSuccess();

        expect(auth.showSetupModal.value).toBe(true);
    });

    it("clears the processing flag when the visit finishes, whatever the outcome", async () => {
        const auth = twoFactor();
        await auth.enableTwoFactor("hunter2");
        expect(auth.processing.value).toBe(true);

        lastRouterOptions(routerMock.post).onFinish();

        expect(auth.processing.value).toBe(false);
    });

    it("preserves scroll and state, so the dashboard does not jump", async () => {
        await twoFactor().enableTwoFactor("hunter2");

        expect(routerMock.post).toHaveBeenCalledWith(
            TWO_FACTOR,
            {},
            expect.objectContaining({ preserveState: true, preserveScroll: true })
        );
    });

    it("clears stale validation errors on a new attempt", async () => {
        setFortifyProps({ requiresPasswordConfirmation: true });
        http.json(CONFIRM_PASSWORD, { errors: { password: ["nope"] } }, 422);
        const auth = twoFactor();
        await auth.enableTwoFactor("wrong");

        http.status(CONFIRM_PASSWORD, 200);
        await auth.enableTwoFactor("hunter2");

        expect(auth.validationErrors.value).toEqual({});
    });
});

describe("useTwoFactorAuth — disableTwoFactor", () => {
    it("sends a DELETE to Fortify", async () => {
        // `router.delete`, not an Inertia `<Form>`: the form would 405 when
        // Fortify's middleware redirects to the GET password-confirm route.
        await twoFactor().disableTwoFactor("hunter2");

        expect(routerMock.delete).toHaveBeenCalledWith(TWO_FACTOR, expect.objectContaining({ preserveScroll: true }));
    });

    it("closes the setup modal straight away", async () => {
        const auth = twoFactor();
        auth.showSetupModal.value = true;

        await auth.disableTwoFactor("hunter2");

        expect(auth.showSetupModal.value).toBe(false);
    });

    it("stops before deleting when the password is wrong", async () => {
        setFortifyProps({ requiresPasswordConfirmation: true });
        http.json(CONFIRM_PASSWORD, { errors: { password: ["nope"] } }, 422);
        const auth = twoFactor();

        await auth.disableTwoFactor("wrong");

        expect(routerMock.delete).not.toHaveBeenCalled();
        expect(auth.processing.value).toBe(false);
    });

    it("wipes the stale enrollment once the server confirms", async () => {
        const auth = twoFactor();
        await auth.fetchSetupData();
        await auth.fetchRecoveryCodes();
        expect(auth.recoveryCodesList.value).not.toEqual([]);

        await auth.disableTwoFactor("hunter2");

        lastRouterOptions(routerMock.delete).onSuccess();

        expect(auth.qrCodeSvg.value).toBeNull();
        expect(auth.recoveryCodesList.value).toEqual([]);
    });
});

describe("useTwoFactorAuth — handleShowRecoveryCodes", () => {
    it("fetches and reveals the codes", async () => {
        const auth = twoFactor();

        await auth.handleShowRecoveryCodes("hunter2");

        expect(auth.recoveryCodesList.value).toEqual(["code-1", "code-2"]);
        expect(auth.isRecoveryCodesVisible.value).toBe(true);
        expect(auth.processing.value).toBe(false);
    });

    it("confirms the password first when Fortify requires it", async () => {
        setFortifyProps({ requiresPasswordConfirmation: true });

        await twoFactor().handleShowRecoveryCodes("hunter2");

        expect(http.callsTo(CONFIRM_PASSWORD)).toHaveLength(1);
    });

    it("reveals nothing when the password is wrong", async () => {
        setFortifyProps({ requiresPasswordConfirmation: true });
        http.json(CONFIRM_PASSWORD, { errors: { password: ["nope"] } }, 422);
        const auth = twoFactor();

        await auth.handleShowRecoveryCodes("wrong");

        expect(http.callsTo(RECOVERY_CODES)).toHaveLength(0);
        expect(auth.isRecoveryCodesVisible.value).toBe(false);
    });

    it("stays hidden when the codes come back empty", async () => {
        http.json(RECOVERY_CODES, []);
        const auth = twoFactor();

        await auth.handleShowRecoveryCodes("hunter2");

        expect(auth.isRecoveryCodesVisible.value).toBe(false);
    });
});

describe("useTwoFactorAuth — handleRegenerateRecoveryCodes", () => {
    /** The regenerate POST and the subsequent GET share a URL, so split by method. */
    const regeneratePosts = (): number => http.callsTo(RECOVERY_CODES).filter(call => call.method === "POST").length;

    it("posts to regenerate, then re-reads the list", async () => {
        const auth = twoFactor();

        await auth.handleRegenerateRecoveryCodes("hunter2");

        expect(regeneratePosts()).toBe(1);
        expect(auth.recoveryCodesList.value).toEqual(["code-1", "code-2"]);
        expect(auth.isRecoveryCodesVisible.value).toBe(true);
    });

    it("does not ask for the password up front", async () => {
        // The session confirmation is usually still valid; asking every time
        // would be a pointless prompt.
        setFortifyProps({ requiresPasswordConfirmation: true });

        await twoFactor().handleRegenerateRecoveryCodes("hunter2");

        expect(http.callsTo(CONFIRM_PASSWORD)).toHaveLength(0);
    });

    it("confirms the password and retries when the session confirmation has expired", async () => {
        // Fortify answers 423 once the password-confirmation window lapses.
        setFortifyProps({ requiresPasswordConfirmation: true });
        let attempt = 0;
        http.json(RECOVERY_CODES, ["code-1"], 200, "GET");
        http.on(
            RECOVERY_CODES,
            () => {
                attempt += 1;
                return new Response(null, { status: attempt === 1 ? 423 : 200 });
            },
            "POST"
        );
        const auth = twoFactor();

        await auth.handleRegenerateRecoveryCodes("hunter2");

        expect(http.callsTo(CONFIRM_PASSWORD)).toHaveLength(1);
        expect(attempt).toBe(2);
        expect(auth.recoveryCodesList.value).toEqual(["code-1"]);
    });

    it("gives up when the re-confirmation is rejected", async () => {
        setFortifyProps({ requiresPasswordConfirmation: true });
        http.status(RECOVERY_CODES, 423);
        http.json(CONFIRM_PASSWORD, { errors: { password: ["nope"] } }, 422);
        const auth = twoFactor();

        await auth.handleRegenerateRecoveryCodes("wrong");

        expect(auth.validationErrors.value).toEqual({ password: "nope" });
        expect(auth.processing.value).toBe(false);
    });

    it("does not retry a 423 when Fortify has password confirmation switched off", async () => {
        setFortifyProps({ requiresPasswordConfirmation: false });
        http.status(RECOVERY_CODES, 423);
        const auth = twoFactor();

        await auth.handleRegenerateRecoveryCodes("hunter2");

        expect(http.callsTo(CONFIRM_PASSWORD)).toHaveLength(0);
        expect(auth.errors.value).toEqual(["Failed to regenerate recovery codes"]);
    });

    it("records an error on any other failure", async () => {
        http.status(RECOVERY_CODES, 500);
        const auth = twoFactor();

        await auth.handleRegenerateRecoveryCodes("hunter2");

        expect(auth.errors.value).toEqual(["Failed to regenerate recovery codes"]);
        expect(auth.processing.value).toBe(false);
    });
});
