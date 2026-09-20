import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import CardImagePreview from "../CardImagePreview.vue";

const SRC = "/card-images/ecl/68618675--0.jpg";

/** The component only shows after its 300ms dwell timer, so drive time by hand. */
beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = "";
});

const render = (props: Record<string, unknown> = {}) => {
    const host = document.createElement("div");
    host.id = "host";
    document.body.append(host);

    return mount(CardImagePreview, {
        props: { src: SRC, alt: "ECL #317", ...props },
        slots: { default: "<span class='label'>Hexing Squelcher</span>" },
        attachTo: document.body
    });
};

/**
 * Hover the trigger and let the dwell timer elapse. `flushPromises` because
 * the timer callback awaits a tick before promoting the popover.
 */
const hover = async (wrapper: ReturnType<typeof render>) => {
    await wrapper.find(".card-preview__trigger").trigger("mouseenter");
    vi.advanceTimersByTime(400);
    await flushPromises();
};

describe("CardImagePreview — where the preview lands", () => {
    it("teleports into the body by default", async () => {
        const wrapper = render();

        await hover(wrapper);

        expect(document.body.querySelector(":scope > .card-preview")).not.toBeNull();
        expect(document.querySelector("#host .card-preview")).toBeNull();
    });

    it("teleports into the requested container instead", async () => {
        // Inside a <dialog> opened with showModal() the preview has to live in
        // the dialog's own subtree — the top layer paints above every z-index,
        // so a preview left in <body> would be hidden behind the modal.
        const wrapper = render({ teleportTo: "#host" });

        await hover(wrapper);

        expect(document.querySelector("#host .card-preview")).not.toBeNull();
        expect(document.body.querySelector(":scope > .card-preview")).toBeNull();
    });

    it("shows the image it was given, wherever it teleports", async () => {
        const wrapper = render({ teleportTo: "#host" });

        await hover(wrapper);

        expect(document.querySelector("#host .card-preview__image")?.getAttribute("src")).toBe(SRC);
    });
});

describe("CardImagePreview — when the preview shows", () => {
    it("stays hidden until the dwell timer elapses", async () => {
        const wrapper = render();

        await wrapper.find(".card-preview__trigger").trigger("mouseenter");
        vi.advanceTimersByTime(200);
        await wrapper.vm.$nextTick();

        expect(document.querySelector(".card-preview")).toBeNull();
    });

    it("hides again on mouse leave", async () => {
        const wrapper = render();
        await hover(wrapper);

        await wrapper.find(".card-preview__trigger").trigger("mouseleave");

        expect(document.querySelector(".card-preview")).toBeNull();
    });

    it("never opens without an image to show", async () => {
        const wrapper = render({ src: null });

        await hover(wrapper);

        expect(document.querySelector(".card-preview")).toBeNull();
    });

    it("still renders its slot when there is no image", () => {
        expect(render({ src: null }).find(".label").exists()).toBe(true);
    });
});

describe("CardImagePreview — escaping a modal", () => {
    it("promotes the preview into the top layer", async () => {
        // The only way out of `Modal.vue`: its content box is `overflow:
        // hidden` and its body scrolls, so a plain positioned child is
        // clipped to the modal, and the dialog's own top-layer promotion
        // paints it above everything left in the normal stacking context.
        const showPopover = vi.spyOn(HTMLElement.prototype, "showPopover");
        const wrapper = render({ teleportTo: "#host" });

        await hover(wrapper);

        expect(showPopover).toHaveBeenCalledTimes(1);
        expect(showPopover.mock.instances[0]).toBe(document.querySelector("#host .card-preview"));
    });

    it("declares the popover manual, so light-dismiss stays the modal's", async () => {
        // An `auto` popover would close on Escape and on outside clicks —
        // both of which belong to the modal underneath it. This one closes
        // on mouseleave and nothing else.
        const wrapper = render();

        await hover(wrapper);

        expect(document.querySelector(".card-preview")?.getAttribute("popover")).toBe("manual");
    });

    it("does not promote anything while the dwell timer is still running", async () => {
        const showPopover = vi.spyOn(HTMLElement.prototype, "showPopover");
        const wrapper = render();

        await wrapper.find(".card-preview__trigger").trigger("mouseenter");
        vi.advanceTimersByTime(200);
        await flushPromises();

        expect(showPopover).not.toHaveBeenCalled();
    });

    it("leaves the top layer by unmounting rather than calling hidePopover", async () => {
        // `hidePopover()` throws on an element that was never shown, and the
        // element is gone the moment `visible` drops — so the call would be
        // both risky and pointless.
        const hidePopover = vi.spyOn(HTMLElement.prototype, "hidePopover");
        const wrapper = render();
        await hover(wrapper);

        await wrapper.find(".card-preview__trigger").trigger("mouseleave");

        expect(document.querySelector(".card-preview")).toBeNull();
        expect(hidePopover).not.toHaveBeenCalled();
    });
});
