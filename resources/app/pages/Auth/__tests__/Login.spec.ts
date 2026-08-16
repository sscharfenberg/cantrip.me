import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import { useToast } from "Composables/useToast.ts";
import Login from "../Login.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

let http: FetchMock;

/** Shared props as `HandleInertiaRequests` ships them for a guest. */
const guestProps = (features: Record<string, boolean> = {}) => ({
    csrfToken: "csrf-token",
    features: { registration: true, resetPasswords: true, emailVerification: true, ...features }
});

beforeEach(() => {
    setPageProps(guestProps());
    http = installFetchMock();
    drainToasts();
});

const render = (props: Record<string, unknown> = {}) => mount(Login, { props });

/** Drain the module-level toast singleton, which outlives one test. */
const drainToasts = (): void => {
    const { activeToasts, removeToast } = useToast();
    for (const toast of [...activeToasts.value]) {
        removeToast(toast.id);
    }
};

/**
 * Fill in the credential fields and submit, waiting for the request the
 * composable fires — `trigger` only awaits Vue's own render queue.
 */
const signIn = async (wrapper: ReturnType<typeof render>): Promise<void> => {
    await wrapper.find("#name").setValue("planeswalker");
    await wrapper.find("#password").setValue("correct horse battery staple");
    await submit(wrapper);
};

/** Submit the form and let the fetch chain settle. */
const submit = async (wrapper: ReturnType<typeof render>): Promise<void> => {
    await wrapper.find("form").trigger("submit");
    await flushPromises();
};

describe("Login — the credentials step", () => {
    it("shows the username and password fields", () => {
        const wrapper = render();

        expect(wrapper.find("#name").exists()).toBe(true);
        expect(wrapper.find("#password").exists()).toBe(true);
    });

    it("does not show the two-factor field yet", () => {
        expect(render().find("#code").exists()).toBe(false);
    });

    it("posts what the user typed", async () => {
        const wrapper = render();

        await signIn(wrapper);

        expect(http.lastCall("/login")?.body).toEqual({
            name: "planeswalker",
            password: "correct horse battery staple",
            remember: true
        });
    });

    it("asks for JSON, which is what keeps the two-factor step on this page", async () => {
        // Without these headers Fortify redirects to its own two-factor route
        // instead of answering `{ two_factor: true }` — see LoginResponse.
        const wrapper = render();

        await signIn(wrapper);

        expect(http.lastCall("/login")?.headers).toMatchObject({
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": "csrf-token"
        });
    });

    it("navigates on success", async () => {
        http.json("/login", { redirect: "/decks" });
        const wrapper = render();

        await signIn(wrapper);

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/decks");
    });
});

describe("Login — the password reveal toggle", () => {
    it("masks the password by default", () => {
        expect(render().find("#password").attributes("type")).toBe("password");
    });

    it("reveals and re-masks it on click", async () => {
        const wrapper = render();
        const toggle = wrapper.find("#password").element.closest(".form-group")?.querySelector("button");

        toggle?.click();
        await wrapper.vm.$nextTick();
        expect(wrapper.find("#password").attributes("type")).toBe("text");

        toggle?.click();
        await wrapper.vm.$nextTick();
        expect(wrapper.find("#password").attributes("type")).toBe("password");
    });
});

describe("Login — validation errors", () => {
    it("shows the server's message against the field it belongs to", async () => {
        http.json("/login", { errors: { name: ["These credentials do not match our records."] } }, 422);
        const wrapper = render();

        await signIn(wrapper);

        expect(wrapper.text()).toContain("These credentials do not match our records.");
    });

    it("keeps the user on the credentials step", async () => {
        http.json("/login", { errors: { name: ["nope"] } }, 422);
        const wrapper = render();

        await signIn(wrapper);

        expect(wrapper.find("#name").exists()).toBe(true);
        expect(wrapper.find("#code").exists()).toBe(false);
    });
});

describe("Login — the two-factor challenge", () => {
    /** Get past the credentials step into the challenge. */
    const challenge = async () => {
        http.json("/login", { two_factor: true });
        const wrapper = render();
        await signIn(wrapper);
        return wrapper;
    };

    it("swaps the credential fields for the code field", async () => {
        const wrapper = await challenge();

        expect(wrapper.find("#name").exists()).toBe(false);
        expect(wrapper.find("#password").exists()).toBe(false);
        expect(wrapper.find("#code").exists()).toBe(true);
    });

    it("does not navigate — the login is not finished yet", async () => {
        await challenge();

        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it("adds the two-factor note to the form legend", async () => {
        const wrapper = await challenge();

        expect(wrapper.text()).toContain("form.legend.2fa");
    });

    it("offers a choice between an authenticator code and a recovery code", async () => {
        const wrapper = await challenge();

        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(2);
    });

    it("starts on the authenticator code, rendering the OTP boxes", async () => {
        const wrapper = await challenge();

        expect(wrapper.findComponent({ name: "OTPInput" }).exists()).toBe(true);
    });

    it("switches to a plain text field when the user picks the recovery code", async () => {
        const wrapper = await challenge();

        await wrapper.findAll('input[type="radio"]')[1].setValue(true);

        expect(wrapper.findComponent({ name: "OTPInput" }).exists()).toBe(false);
        expect(wrapper.find("#code").attributes("type")).toBe("text");
    });

    it("switches back to the OTP boxes when the user picks the code again", async () => {
        const wrapper = await challenge();
        await wrapper.findAll('input[type="radio"]')[1].setValue(true);

        await wrapper.findAll('input[type="radio"]')[0].setValue(true);

        expect(wrapper.findComponent({ name: "OTPInput" }).exists()).toBe(true);
    });

    it("sends an authenticator code under `code`", async () => {
        // The field name is what `FailedTwoFactorLoginResponse` branches on to
        // decide which error key comes back, so the two are not interchangeable.
        const wrapper = await challenge();
        wrapper.findComponent({ name: "OTPInput" }).vm.$emit("update:modelValue", "123456");
        await wrapper.vm.$nextTick();

        await submit(wrapper);

        expect(http.lastCall("/two-factor-challenge")?.body).toEqual({ code: "123456" });
    });

    it("sends a recovery code under `recovery_code`", async () => {
        const wrapper = await challenge();
        await wrapper.findAll('input[type="radio"]')[1].setValue(true);
        await wrapper.find("#code").setValue("abcdef-123456");

        await submit(wrapper);

        expect(http.lastCall("/two-factor-challenge")?.body).toEqual({ recovery_code: "abcdef-123456" });
    });

    it("signs the user in once the code is accepted", async () => {
        const wrapper = await challenge();
        http.json("/two-factor-challenge", { redirect: "/decks" });

        await submit(wrapper);

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/decks");
    });

    it("falls back to the dashboard when the server names no redirect", async () => {
        const wrapper = await challenge();
        http.json("/two-factor-challenge", {});

        await submit(wrapper);

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/dashboard");
    });

    it("shows a rejected recovery code against its own field", async () => {
        // The two error keys render through different branches of the template.
        const wrapper = await challenge();
        await wrapper.findAll('input[type="radio"]')[1].setValue(true);
        http.json("/two-factor-challenge", { errors: { recovery_code: ["That code has been used."] } }, 422);

        await submit(wrapper);

        expect(wrapper.text()).toContain("That code has been used.");
    });

    it("shows a rejected code as an inline error", async () => {
        const wrapper = await challenge();
        http.json("/two-factor-challenge", { errors: { code: ["The provided code was invalid."] } }, 422);

        await submit(wrapper);

        expect(wrapper.text()).toContain("The provided code was invalid.");
        expect(wrapper.find("#code").exists()).toBe(true);
    });
});

describe("Login — feature-gated links", () => {
    it("links to registration, password reset and re-verification when all are on", () => {
        const hrefs = render()
            .findAll("a")
            .map(link => link.attributes("href"));

        expect(hrefs).toEqual(expect.arrayContaining(["/register", "/forgot", "/resend-verification"]));
    });

    it("hides the registration link when registration is closed", () => {
        setPageProps(guestProps({ registration: false }));

        const hrefs = render()
            .findAll("a")
            .map(link => link.attributes("href"));

        expect(hrefs).not.toContain("/register");
        expect(hrefs).toContain("/forgot");
    });

    it("hides the password-reset link when the feature is off", () => {
        setPageProps(guestProps({ resetPasswords: false }));

        expect(
            render()
                .findAll("a")
                .map(link => link.attributes("href"))
        ).not.toContain("/forgot");
    });

    it("hides the re-verification link when email verification is off", () => {
        setPageProps(guestProps({ emailVerification: false }));

        expect(
            render()
                .findAll("a")
                .map(link => link.attributes("href"))
        ).not.toContain("/resend-verification");
    });
});

describe("Login — Fortify's session status", () => {
    it("surfaces it as a toast", () => {
        // Fortify writes `session('status')` after a password reset; the app's
        // own flash bridge reads `session('message')`, so this page has to
        // raise it itself or the message is lost.
        render({ status: "Your password has been reset." });

        expect(useToast().activeToasts.value.map(toast => toast.message)).toEqual(["Your password has been reset."]);
    });

    it("raises nothing when there is no status", () => {
        render();

        expect(useToast().activeToasts.value).toEqual([]);
    });

    it("raises nothing for an empty status", () => {
        render({ status: "" });

        expect(useToast().activeToasts.value).toEqual([]);
    });
});
