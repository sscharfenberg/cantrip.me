import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import Modal from "../Modal.vue";

/**
 * jsdom implements `<dialog>` only partially, so `showModal` / `close` are
 * replaced with spies that maintain the `open` flag. That keeps the component's
 * own branching — "already closing", "not open" — observable, which is the part
 * worth testing; the browser's own dialog behaviour is not.
 */
let showModal: ReturnType<typeof vi.fn>;
let close: ReturnType<typeof vi.fn>;
let originalShowModal: PropertyDescriptor | undefined;
let originalClose: PropertyDescriptor | undefined;

beforeEach(() => {
    originalShowModal = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, "showModal");
    originalClose = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, "close");
    showModal = vi.fn(function (this: HTMLDialogElement) {
        this.open = true;
    });
    close = vi.fn(function (this: HTMLDialogElement) {
        this.open = false;
    });
    Object.defineProperty(HTMLDialogElement.prototype, "showModal", { value: showModal, configurable: true });
    Object.defineProperty(HTMLDialogElement.prototype, "close", { value: close, configurable: true });
});

afterEach(() => {
    // Restored rather than deleted: jsdom does not implement these today, but
    // deleting would strip the natives for the rest of the file if it ever does.
    const restore = (name: "showModal" | "close", descriptor: PropertyDescriptor | undefined): void => {
        if (descriptor) Object.defineProperty(HTMLDialogElement.prototype, name, descriptor);
        else Reflect.deleteProperty(HTMLDialogElement.prototype, name);
    };
    restore("showModal", originalShowModal);
    restore("close", originalClose);
    document.body.innerHTML = "";
});

/** Mount the modal and hand back the teleported dialog element. */
const open = (options: Parameters<typeof mount>[1] = {}) => {
    const wrapper = mount(Modal, { attachTo: document.body, ...options });
    const dialog = document.querySelector("dialog") as HTMLDialogElement;
    return { wrapper, dialog, content: dialog.querySelector(".modal-dialog__content") as HTMLElement };
};

describe("Modal — opening", () => {
    it("opens itself on mount, so rendering it means showing it", () => {
        open();

        expect(showModal).toHaveBeenCalledOnce();
    });

    it("teleports out of its parent, so a wrapping link cannot swallow clicks", () => {
        // Mounted inside an Inertia <Link> on the deck list, a click on the
        // close button would otherwise still be a click inside the anchor, and
        // the browser's default navigation would fire.
        const host = defineComponent({
            components: { Modal },
            template: `<a href="/decks/1" class="deck-link"><Modal /></a>`
        });
        mount(host, { attachTo: document.body });

        const dialog = document.querySelector("dialog") as HTMLDialogElement;
        const link = document.querySelector(".deck-link") as HTMLElement;

        expect(dialog.parentElement).toBe(document.body);
        expect(link.contains(dialog)).toBe(false);
    });

    it("renders the header, body and footer slots", () => {
        const { dialog } = open({ slots: { header: "Delete deck", default: "Are you sure?", footer: "Cancel" } });

        expect(dialog.textContent).toContain("Delete deck");
        expect(dialog.textContent).toContain("Are you sure?");
        expect(dialog.textContent).toContain("Cancel");
    });

    it("omits the footer when no footer slot is filled", () => {
        const { dialog } = open({ slots: { default: "Are you sure?" } });

        expect(dialog.querySelector(".modal-dialog__footer")).toBeNull();
    });
});

describe("Modal — closing without animation", () => {
    beforeEach(() => {
        vi.stubGlobal("matchMedia", () => ({ matches: true, media: "", addEventListener: () => {} }));
    });

    it("closes immediately when the user prefers reduced motion", async () => {
        const { wrapper, dialog } = open();

        dialog.dispatchEvent(new MouseEvent("click"));
        await wrapper.vm.$nextTick();

        expect(close).toHaveBeenCalledOnce();
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("ignores a click on the content, so only the backdrop dismisses", async () => {
        const { wrapper, content } = open();

        content.dispatchEvent(new MouseEvent("click", { bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(close).not.toHaveBeenCalled();
    });

    it("runs its own close path on Escape rather than the browser's", async () => {
        const { wrapper, dialog } = open();
        const cancel = new Event("cancel", { cancelable: true });

        dialog.dispatchEvent(cancel);
        await wrapper.vm.$nextTick();

        // Prevented so the exit animation gets a chance to run; the component
        // calls `close()` itself.
        expect(cancel.defaultPrevented).toBe(true);
        expect(close).toHaveBeenCalledOnce();
    });

    it("does nothing when it is already closed", async () => {
        const { wrapper, dialog } = open();
        dialog.dispatchEvent(new MouseEvent("click"));
        await wrapper.vm.$nextTick();
        close.mockClear();

        dialog.dispatchEvent(new MouseEvent("click"));
        await wrapper.vm.$nextTick();

        expect(close).not.toHaveBeenCalled();
    });
});

describe("Modal — closing with the exit animation", () => {
    beforeEach(() => {
        vi.stubGlobal("matchMedia", () => ({ matches: false, media: "", addEventListener: () => {} }));
    });

    it("waits for the animation before closing the dialog", async () => {
        const { wrapper, dialog, content } = open();

        dialog.dispatchEvent(new MouseEvent("click"));
        await wrapper.vm.$nextTick();

        expect(dialog.classList.contains("is-closing")).toBe(true);
        expect(close).not.toHaveBeenCalled();

        content.dispatchEvent(new AnimationEvent("animationend"));
        await wrapper.vm.$nextTick();

        expect(close).toHaveBeenCalledOnce();
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("ignores an animation that finished on a descendant", async () => {
        const { wrapper, dialog, content } = open({ slots: { default: '<p class="inner">Body</p>' } });
        dialog.dispatchEvent(new MouseEvent("click"));
        await wrapper.vm.$nextTick();

        const inner = content.querySelector(".inner") as HTMLElement;
        inner.dispatchEvent(new AnimationEvent("animationend", { bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(close).not.toHaveBeenCalled();
    });

    it("does not start a second close while one is animating", async () => {
        const { wrapper, dialog, content } = open();
        dialog.dispatchEvent(new MouseEvent("click"));
        await wrapper.vm.$nextTick();

        dialog.dispatchEvent(new MouseEvent("click"));
        content.dispatchEvent(new AnimationEvent("animationend"));
        await wrapper.vm.$nextTick();

        expect(close).toHaveBeenCalledOnce();
        expect(wrapper.emitted("close")).toHaveLength(1);
    });
});
