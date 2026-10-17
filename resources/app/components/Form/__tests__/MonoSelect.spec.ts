import { mount } from "@vue/test-utils";
import { afterEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import MonoSelect from "../Select/MonoSelect.vue";

const options = ["Alpha", "Beta", "Gamma", "Delta"].map(label => ({ value: label.toLowerCase(), label }));

afterEach(() => {
    vi.restoreAllMocks();
});

/** Give an element the layout jsdom never computes. */
const layout = (el: Element, box: { offsetTop?: number; offsetHeight?: number; clientHeight?: number }): void => {
    for (const [key, value] of Object.entries(box)) Object.defineProperty(el, key, { configurable: true, value });
};

/**
 * Open the menu and lay the list out as a 40px-tall scroller of 30px
 * options — three of the four sit below its fold.
 */
const openMenu = async () => {
    const wrapper = mount(MonoSelect, { props: { options, sort: false }, attachTo: document.body });
    await wrapper.get(".form-select__button").trigger("click");
    await nextTick();
    const scroller = wrapper.get(".form-select__scroll").element as HTMLElement;
    layout(scroller, { offsetTop: 10, clientHeight: 40 });
    wrapper.findAll(".form-select__option").forEach((option, i) => {
        layout(option.element, { offsetTop: 10 + i * 30, offsetHeight: 30 });
    });
    return { wrapper, scroller };
};

describe("MonoSelect — revealing an option", () => {
    it("scrolls only the list to a typed-ahead option, never the page", async () => {
        // scrollIntoView would also scroll the page: the list is a fixed popover
        // anchored to the trigger, so the browser chased it to the page bottom.
        const scrollIntoView = vi.spyOn(Element.prototype, "scrollIntoView");
        const focus = vi.spyOn(HTMLElement.prototype, "focus");
        const { wrapper, scroller } = await openMenu();

        document.dispatchEvent(new KeyboardEvent("keydown", { key: "d" }));

        // Delta: 90px down, 30px tall, in a 40px window — scrolled to its bottom edge.
        expect(scroller.scrollTop).toBe(80);
        expect(scrollIntoView).not.toHaveBeenCalled();
        expect(focus).toHaveBeenCalledWith({ preventScroll: true });
        wrapper.unmount();
    });

    it("leaves the list where it is when the option is already in view", async () => {
        const { wrapper, scroller } = await openMenu();

        document.dispatchEvent(new KeyboardEvent("keydown", { key: "a" }));

        expect(scroller.scrollTop).toBe(0);
        wrapper.unmount();
    });
});
