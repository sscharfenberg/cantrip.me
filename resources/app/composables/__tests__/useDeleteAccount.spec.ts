import { beforeEach, describe, expect, it, vi } from "vitest";
import { installFetchMock } from "@/test/http.ts";
import type { FetchMock } from "@/test/http.ts";
import { routerMock, setPageProps } from "@/test/inertia.ts";
import { useDeleteAccount } from "../useDeleteAccount.ts";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

let http: FetchMock;

beforeEach(() => {
    setPageProps({ csrfToken: "csrf-token" });
    http = installFetchMock();
});

describe("useDeleteAccount — the request", () => {
    it("starts idle with no error", () => {
        const account = useDeleteAccount();

        expect(account.processing.value).toBe(false);
        expect(account.passwordError.value).toBe("");
    });

    it("sends a DELETE carrying the confirmation password", async () => {
        await useDeleteAccount().deleteAccount("hunter2");

        expect(http.lastCall("/user/delete")).toMatchObject({
            method: "DELETE",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": "csrf-token"
            },
            body: { password: "hunter2" }
        });
    });

    it("uses fetch rather than the Inertia router, keeping the page behind the modal still", async () => {
        // A `router.delete` would re-render the dashboard under the open modal
        // on every wrong password: scroll jump, global error bag, the lot.
        await useDeleteAccount().deleteAccount("hunter2");

        expect(routerMock.delete).not.toHaveBeenCalled();
        expect(http.callsTo("/user/delete")).toHaveLength(1);
    });
});

describe("useDeleteAccount — success", () => {
    it("follows the redirect the server returns", async () => {
        http.json("/user/delete", { redirect: "/goodbye" });

        await useDeleteAccount().deleteAccount("hunter2");

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/goodbye");
    });

    it("falls back to the home page when the server names no redirect", async () => {
        http.json("/user/delete", {});

        await useDeleteAccount().deleteAccount("hunter2");

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/");
    });

    it("survives a success response that is not JSON", async () => {
        http.malformed("/user/delete");

        await useDeleteAccount().deleteAccount("hunter2");

        expect(routerMock.visit).toHaveBeenCalledExactlyOnceWith("/");
    });
});

describe("useDeleteAccount — wrong password", () => {
    it("surfaces the first message inline and does not navigate", async () => {
        http.json("/user/delete", { errors: { password: ["The password is incorrect.", "second"] } }, 422);
        const account = useDeleteAccount();

        await account.deleteAccount("wrong");

        expect(account.passwordError.value).toBe("The password is incorrect.");
        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it("accepts a bare string as well as an array", async () => {
        http.json("/user/delete", { errors: { password: "The password is incorrect." } }, 422);
        const account = useDeleteAccount();

        await account.deleteAccount("wrong");

        expect(account.passwordError.value).toBe("The password is incorrect.");
    });

    it("shows nothing when the 422 carries no password message", async () => {
        http.json("/user/delete", { errors: {} }, 422);
        const account = useDeleteAccount();

        await account.deleteAccount("wrong");

        expect(account.passwordError.value).toBe("");
    });

    it("survives a 422 whose body is not JSON", async () => {
        http.malformed("/user/delete", 422);
        const account = useDeleteAccount();

        await expect(account.deleteAccount("wrong")).resolves.toBeUndefined();
        expect(account.passwordError.value).toBe("");
    });

    it("clears the previous message before retrying", async () => {
        http.json("/user/delete", { errors: { password: ["nope"] } }, 422);
        const account = useDeleteAccount();
        await account.deleteAccount("wrong");

        http.json("/user/delete", { redirect: "/goodbye" });
        await account.deleteAccount("hunter2");

        expect(account.passwordError.value).toBe("");
    });
});

describe("useDeleteAccount — processing flag", () => {
    it("is raised while the request is in flight", async () => {
        let duringRequest: boolean | null = null;
        const account = useDeleteAccount();
        http.on("/user/delete", () => {
            duringRequest = account.processing.value;
            return new Response("{}", { status: 200 });
        });

        await account.deleteAccount("hunter2");

        expect(duringRequest).toBe(true);
    });

    it("is lowered again after every outcome", async () => {
        const account = useDeleteAccount();

        http.json("/user/delete", { errors: { password: ["nope"] } }, 422);
        await account.deleteAccount("wrong");
        expect(account.processing.value).toBe(false);

        http.status("/user/delete", 500);
        await account.deleteAccount("wrong");
        expect(account.processing.value).toBe(false);

        http.json("/user/delete", { redirect: "/goodbye" });
        await account.deleteAccount("hunter2");
        expect(account.processing.value).toBe(false);
    });

    it("is lowered even when the request itself throws", async () => {
        // The `finally` block is what stops the modal's button staying disabled
        // after a dropped connection.
        http.reject("/user/delete");
        const account = useDeleteAccount();

        await expect(account.deleteAccount("hunter2")).rejects.toThrow();
        expect(account.processing.value).toBe(false);
    });
});
