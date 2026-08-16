import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import Accordion from "../Accordion.vue";

/**
 * The slide animation is driven by `requestAnimationFrame` plus a real
 * `transitionend`, neither of which jsdom produces on its own: rAF is stubbed
 * to run synchronously and the transition is ended by hand. `scrollHeight` is
 * always 0 in jsdom, so the measured heights are not asserted — the states
 * either side of the animation are.
 */
let rafSpy: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
    rafSpy = vi.spyOn(globalThis, "requestAnimationFrame").mockImplementation(callback => {
        callback(0);
        return 0;
    });
});

afterEach(() => {
    rafSpy.mockRestore();
});

const render = (props: Record<string, unknown> = {}) =>
    mount(Accordion, {
        props,
        slots: { head: "Deck statistics", body: '<p class="panel">Mana curve</p>' }
    });

/** Finish the height transition the component is waiting on. */
const endTransition = async (wrapper: ReturnType<typeof render>): Promise<void> => {
    const body = wrapper.find(".collapsible__body").element;
    body.dispatchEvent(new TransitionEvent("transitionend", { propertyName: "height" }));
    await wrapper.vm.$nextTick();
};

const isBodyVisible = (wrapper: ReturnType<typeof render>): boolean =>
    (wrapper.find(".collapsible__body").element as HTMLElement).style.display !== "none";

describe("Accordion — initial state", () => {
    it("starts closed by default", () => {
        const wrapper = render();

        expect(wrapper.find("button").attributes("aria-expanded")).toBe("false");
        expect(isBodyVisible(wrapper)).toBe(false);
    });

    it("starts open when asked, so a persisted choice survives the mount", () => {
        const wrapper = render({ initialOpen: true });

        expect(wrapper.find("button").attributes("aria-expanded")).toBe("true");
        expect(isBodyVisible(wrapper)).toBe(true);
    });

    it("renders the head and body slots", () => {
        const wrapper = render({ initialOpen: true });

        expect(wrapper.find(".collapsible__head").text()).toContain("Deck statistics");
        expect(wrapper.find(".panel").text()).toBe("Mana curve");
    });

    it("passes the open state into the head slot", () => {
        const wrapper = mount(Accordion, {
            props: { initialOpen: true },
            slots: { head: '<template #head="{ isOpen }">{{ isOpen ? "open" : "shut" }}</template>' }
        });

        expect(wrapper.find(".collapsible__head").text()).toContain("open");
    });
});

describe("Accordion — toggling", () => {
    it("reports the new state immediately, so the chevron turns without waiting", async () => {
        const wrapper = render();

        await wrapper.find("button").trigger("click");

        expect(wrapper.find("button").attributes("aria-expanded")).toBe("true");
        expect(wrapper.emitted("toggle")).toEqual([[true]]);
    });

    it("shows the body as soon as it opens, before the animation finishes", async () => {
        // The element has to exist to be measured and slid open.
        const wrapper = render();

        await wrapper.find("button").trigger("click");

        expect(isBodyVisible(wrapper)).toBe(true);
    });

    it("keeps the body mounted until the closing animation ends", async () => {
        const wrapper = render({ initialOpen: true });

        await wrapper.find("button").trigger("click");
        expect(isBodyVisible(wrapper)).toBe(true);

        await endTransition(wrapper);
        expect(isBodyVisible(wrapper)).toBe(false);
    });

    it("emits the closed state on the way down", async () => {
        const wrapper = render({ initialOpen: true });

        await wrapper.find("button").trigger("click");

        expect(wrapper.emitted("toggle")).toEqual([[false]]);
    });

    it("clears its inline heights once the animation is done", async () => {
        // Left behind, they would stop the panel reflowing when its content
        // changes while open.
        const wrapper = render();
        await wrapper.find("button").trigger("click");
        await wrapper.vm.$nextTick();

        await endTransition(wrapper);

        const body = wrapper.find(".collapsible__body").element as HTMLElement;
        expect(body.style.height).toBe("");
        expect(body.style.overflow).toBe("");
    });
});

describe("Accordion — guarding against double clicks", () => {
    it("ignores a second click while the first is still animating", async () => {
        const wrapper = render();

        await wrapper.find("button").trigger("click");
        await wrapper.find("button").trigger("click");

        expect(wrapper.emitted("toggle")).toEqual([[true]]);
    });

    it("accepts the next click once the animation has finished", async () => {
        const wrapper = render();
        await wrapper.find("button").trigger("click");
        await wrapper.vm.$nextTick();
        await endTransition(wrapper);

        await wrapper.find("button").trigger("click");

        expect(wrapper.emitted("toggle")).toEqual([[true], [false]]);
    });

    it("ignores a transition that ended on some other property", async () => {
        const wrapper = render();
        await wrapper.find("button").trigger("click");
        await wrapper.vm.$nextTick();

        wrapper
            .find(".collapsible__body")
            .element.dispatchEvent(new TransitionEvent("transitionend", { propertyName: "opacity" }));
        await wrapper.find("button").trigger("click");

        expect(wrapper.emitted("toggle")).toEqual([[true]]);
    });
});

describe("Accordion — setOpen", () => {
    it("opens from outside, for a parent driving several panels at once", async () => {
        const wrapper = render();

        (wrapper.vm as unknown as { setOpen: (value: boolean) => void }).setOpen(true);
        await wrapper.vm.$nextTick();

        expect(isBodyVisible(wrapper)).toBe(true);
    });

    it("does nothing when the requested state is already the current one", async () => {
        // Without the guard the redundant call would start an animation nobody
        // ends, latching `animating` and swallowing the user's next click.
        const wrapper = render({ initialOpen: true });

        (wrapper.vm as unknown as { setOpen: (value: boolean) => void }).setOpen(true);
        await wrapper.vm.$nextTick();

        expect(isBodyVisible(wrapper)).toBe(true);

        await wrapper.find("button").trigger("click");

        expect(wrapper.emitted("toggle")).toEqual([[false]]);
    });

    it("does not emit toggle — the caller already knows what it asked for", async () => {
        const wrapper = render();

        (wrapper.vm as unknown as { setOpen: (value: boolean) => void }).setOpen(true);
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted("toggle")).toBeUndefined();
    });
});
