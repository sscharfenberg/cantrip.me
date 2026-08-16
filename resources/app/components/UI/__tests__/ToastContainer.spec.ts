import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { setPageProps } from "@/test/inertia.ts";
import { useToast } from "Composables/useToast.ts";
import ToastContainer from "../ToastContainer.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

/** Drain the module-level toast singleton, which outlives a single test. */
const drainToasts = (): void => {
    const { activeToasts, removeToast } = useToast();
    for (const toast of [...activeToasts.value]) {
        removeToast(toast.id);
    }
};

beforeEach(() => {
    vi.useFakeTimers();
    drainToasts();
    setPageProps({ flash: {} });
});

afterEach(() => {
    // The container itself is unmounted by the harness; the toast singleton it
    // reads is module state and has to be emptied by hand.
    drainToasts();
    vi.useRealTimers();
});

/** Mount the container and hand back the teleported region. */
const render = () => {
    const wrapper = mount(ToastContainer, { attachTo: document.body });
    return { wrapper, region: document.querySelector(".toast-container") as HTMLElement };
};

const messages = (): string[] =>
    [...document.querySelectorAll(".toast-container__item span")].map(node => node.textContent ?? "");

describe("ToastContainer — rendering", () => {
    it("teleports a live region into the body", () => {
        const { region } = render();

        expect(region.getAttribute("role")).toBe("region");
        expect(region.getAttribute("aria-live")).toBe("polite");
    });

    it("shows nothing when there are no toasts", () => {
        render();

        expect(document.querySelectorAll(".toast-container__item")).toHaveLength(0);
    });

    it("renders each active toast as its own alert", async () => {
        const { wrapper } = render();

        useToast().addToast("Saved!", "success");
        await wrapper.vm.$nextTick();

        expect(messages()).toEqual(["Saved!"]);
        expect(document.querySelector(".toast-container__item")?.getAttribute("role")).toBe("alert");
    });

    it("carries the severity as a class", async () => {
        const { wrapper } = render();

        useToast().addToast("Boom", "error");
        await wrapper.vm.$nextTick();

        expect(document.querySelector(".toast-container__item")?.className).toContain("toast-container__item--error");
    });

    it.each([
        ["success", "#check"],
        ["warning", "#warning"],
        ["error", "#error"],
        ["info", "#info"]
    ])("shows a %s toast with the %s icon", async (type, icon) => {
        const { wrapper } = render();

        useToast().addToast("Message", type as "info");
        await wrapper.vm.$nextTick();

        expect(document.querySelector(".toast-container__item use")?.getAttribute("xlink:href")).toBe(icon);
    });
});

describe("ToastContainer — the progress bar", () => {
    it("publishes the duration so the bar can animate over it", async () => {
        const { wrapper } = render();

        useToast().addToast("Saved!", "info", 7000);
        await wrapper.vm.$nextTick();

        const item = document.querySelector(".toast-container__item") as HTMLElement;
        expect(item.style.getPropertyValue("--toast-duration")).toBe("7000ms");
        expect(document.querySelector(".toast-container__progress")).not.toBeNull();
    });

    it("omits the bar for a toast that never expires", async () => {
        const { wrapper } = render();

        useToast().addToast("Stays until closed", "warning", 0);
        await wrapper.vm.$nextTick();

        expect(document.querySelector(".toast-container__progress")).toBeNull();
    });
});

describe("ToastContainer — dismissal", () => {
    it("removes a toast when its close button is clicked", async () => {
        const { wrapper } = render();
        useToast().addToast("Saved!", "info", 0);
        await wrapper.vm.$nextTick();

        (document.querySelector(".btn-close") as HTMLButtonElement).click();
        await wrapper.vm.$nextTick();

        expect(messages()).toEqual([]);
    });
});

describe("ToastContainer — Inertia flash messages", () => {
    it("raises a toast for a message present on first render", async () => {
        // `{ immediate: true }` is what catches a flash that arrived with the
        // initial page load rather than with a later visit.
        setPageProps({ flash: { nonce: "n1", message: "Deck saved", type: "success" } });

        const { wrapper } = render();
        await wrapper.vm.$nextTick();

        expect(messages()).toEqual(["Deck saved"]);
    });

    it("raises a toast when a later response flashes one", async () => {
        const { wrapper } = render();

        setPageProps({ flash: { nonce: "n1", message: "Deck saved", type: "success" } });
        await wrapper.vm.$nextTick();

        expect(messages()).toEqual(["Deck saved"]);
    });

    it("fires again for an identical message under a new nonce", async () => {
        // Two consecutive submits produce the same text; watching the flash
        // object itself would silently swallow the second.
        const { wrapper } = render();
        setPageProps({ flash: { nonce: "n1", message: "Deck saved", type: "success" } });
        await wrapper.vm.$nextTick();

        setPageProps({ flash: { nonce: "n2", message: "Deck saved", type: "success" } });
        await wrapper.vm.$nextTick();

        expect(messages()).toEqual(["Deck saved", "Deck saved"]);
    });

    it("ignores a response that carries no flash at all", async () => {
        const { wrapper } = render();

        setPageProps({});
        await wrapper.vm.$nextTick();

        expect(messages()).toEqual([]);
    });

    it("ignores a message that arrives without a nonce", async () => {
        // The nonce is the signal; a message without one is a stale prop the
        // watcher must not replay.
        const { wrapper } = render();

        setPageProps({ flash: { message: "Deck saved", type: "success" } });
        await wrapper.vm.$nextTick();

        expect(messages()).toEqual([]);
    });

    it("ignores a nonce with no message behind it", async () => {
        const { wrapper } = render();

        setPageProps({ flash: { nonce: "n1" } });
        await wrapper.vm.$nextTick();

        expect(messages()).toEqual([]);
    });

    it("defaults an untyped flash to the informational style", async () => {
        const { wrapper } = render();

        setPageProps({ flash: { nonce: "n1", message: "Something happened" } });
        await wrapper.vm.$nextTick();

        expect(document.querySelector(".toast-container__item")?.className).toContain("toast-container__item--info");
    });
});
