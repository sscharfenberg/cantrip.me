import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import Switch from "../Switch.vue";

describe("Switch", () => {
    it("starts unchecked by default", () => {
        const wrapper = mount(Switch, { props: { refId: "remember" } });

        expect((wrapper.find("input").element as HTMLInputElement).checked).toBe(false);
    });

    it("honours an initially-checked state", () => {
        const wrapper = mount(Switch, { props: { refId: "remember", checkedInitially: true } });

        expect((wrapper.find("input").element as HTMLInputElement).checked).toBe(true);
    });

    it("ties the label to the input, so clicking the label toggles it", () => {
        const wrapper = mount(Switch, { props: { refId: "remember", label: "Remember me" } });

        expect(wrapper.find("input").attributes("id")).toBe("remember");
        expect(wrapper.find("label").attributes("for")).toBe("remember");
        expect(wrapper.find("label").text()).toBe("Remember me");
    });

    it("uses the same string for id and name, so the value posts under a known key", () => {
        const wrapper = mount(Switch, { props: { refId: "remember" } });

        expect(wrapper.find("input").attributes("name")).toBe("remember");
    });

    it("submits 'true' unless told otherwise", () => {
        expect(
            mount(Switch, { props: { refId: "a" } })
                .find("input")
                .attributes("value")
        ).toBe("true");
        expect(
            mount(Switch, { props: { refId: "a", value: "1" } })
                .find("input")
                .attributes("value")
        ).toBe("1");
    });

    it("generates a unique id when the caller supplies none", () => {
        // Two switches on one page must not share an id, or the second label
        // would toggle the first input.
        const first = mount(Switch).find("input").attributes("id");
        const second = mount(Switch).find("input").attributes("id");

        expect(first).toBeTruthy();
        expect(first).not.toBe(second);
    });

    it("can be disabled", () => {
        // Declared as a prop but not bound to the input until this test caught
        // it, so a disabled switch was still operable.
        const wrapper = mount(Switch, { props: { refId: "remember", disabled: true } });

        expect((wrapper.find("input").element as HTMLInputElement).disabled).toBe(true);
    });

    it("is operable unless disabled", () => {
        const wrapper = mount(Switch, { props: { refId: "remember" } });

        expect((wrapper.find("input").element as HTMLInputElement).disabled).toBe(false);
    });

    it("emits the new state when the user toggles it", async () => {
        const wrapper = mount(Switch, { props: { refId: "remember" } });

        await wrapper.find("input").setValue(true);
        expect(wrapper.emitted("change")).toEqual([[true]]);

        await wrapper.find("input").setValue(false);
        expect(wrapper.emitted("change")).toEqual([[true], [false]]);
    });
});
