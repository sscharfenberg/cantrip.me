import { mount } from "@vue/test-utils";
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

/** Hover the trigger and let the dwell timer elapse. */
const hover = async (wrapper: ReturnType<typeof render>) => {
    await wrapper.find(".card-preview__trigger").trigger("mouseenter");
    vi.advanceTimersByTime(400);
    await wrapper.vm.$nextTick();
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
