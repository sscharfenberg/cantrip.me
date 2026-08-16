import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import AccountDeleteModal from "../AccountDeleteModal.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

/** The modal teleports into `<body>`, so assertions go through `document`. */
const passwordField = (): HTMLInputElement => document.querySelector("#password") as HTMLInputElement;
const submitButton = (): HTMLButtonElement =>
    document.querySelector(".modal-dialog__footer button") as HTMLButtonElement;
const revealButton = (): HTMLButtonElement => document.querySelector(".form-group__button button") as HTMLButtonElement;

let http: FetchMock;

beforeEach(() => {
    setPageProps({ csrfToken: "csrf-token" });
    http = installFetchMock();
});

const render = () => mount(AccountDeleteModal, { attachTo: document.body });

/** Type a password and submit the form. */
const confirmWith = async (password: string): Promise<void> => {
    const field = passwordField();
    field.value = password;
    field.dispatchEvent(new Event("input"));
    await flushPromises();

    (document.querySelector("#account-delete-form") as HTMLFormElement).dispatchEvent(
        new Event("submit", { cancelable: true })
    );
    await flushPromises();
};

describe("AccountDeleteModal — the form", () => {
    it("focuses the password field, so the user can type straight away", () => {
        render();

        expect(document.activeElement).toBe(passwordField());
    });

    it("masks the password and can reveal it", async () => {
        const wrapper = render();

        expect(passwordField().type).toBe("password");

        revealButton().click();
        await wrapper.vm.$nextTick();

        expect(passwordField().type).toBe("text");
    });

    it("refuses to submit an empty password", () => {
        render();

        expect(submitButton().disabled).toBe(true);
    });

    it("enables the button once something is typed", async () => {
        const wrapper = render();

        passwordField().value = "hunter2";
        passwordField().dispatchEvent(new Event("input"));
        await wrapper.vm.$nextTick();

        expect(submitButton().disabled).toBe(false);
    });
});

describe("AccountDeleteModal — deleting", () => {
    it("sends the typed password to the delete endpoint", async () => {
        render();

        await confirmWith("hunter2");

        expect(http.lastCall("/user/delete")).toMatchObject({
            method: "DELETE",
            body: { password: "hunter2" }
        });
    });

    it("follows the redirect the server returns", async () => {
        http.json("/user/delete", { redirect: "/goodbye" });
        render();

        await confirmWith("hunter2");

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/goodbye");
    });
});

describe("AccountDeleteModal — a wrong password", () => {
    beforeEach(() => {
        http.json("/user/delete", { errors: { password: ["The password is incorrect."] } }, 422);
    });

    it("shows the message inline", async () => {
        render();

        await confirmWith("wrong");

        expect(document.querySelector(".form-group__error")?.textContent).toContain("The password is incorrect.");
    });

    it("keeps the modal open, rather than closing on a failed attempt", async () => {
        const wrapper = render();

        await confirmWith("wrong");

        expect(wrapper.emitted("close")).toBeUndefined();
        expect(document.querySelector("#account-delete-form")).not.toBeNull();
    });

    it("does not navigate away", async () => {
        render();

        await confirmWith("wrong");

        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it("lets the user try again", async () => {
        render();
        await confirmWith("wrong");

        http.json("/user/delete", { redirect: "/goodbye" });
        await confirmWith("hunter2");

        expect(document.querySelector(".form-group__error")).toBeNull();
        expect(routerMock.visit).toHaveBeenCalledWith("/goodbye");
    });
});
