import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { nextTick } from "vue";
import Checkbox from "../Checkbox.vue";

/** The rendered `<input>` as a real DOM node, for the properties HTML has no attribute for. */
const inputOf = (wrapper: ReturnType<typeof mount>): HTMLInputElement =>
    wrapper.find("input").element as HTMLInputElement;

describe("Checkbox — initial state", () => {
    it("starts unchecked by default", () => {
        expect(inputOf(mount(Checkbox, { props: { refId: "all" } })).checked).toBe(false);
    });

    it("honours an initially-checked state", () => {
        expect(inputOf(mount(Checkbox, { props: { refId: "all", checkedInitially: true } })).checked).toBe(true);
    });

    it("ties the label to the input and reuses the id as the form name", () => {
        const wrapper = mount(Checkbox, { props: { refId: "all", label: "Select all" } });

        expect(wrapper.find("input").attributes()).toMatchObject({ id: "all", name: "all" });
        expect(wrapper.find("label").attributes("for")).toBe("all");
        expect(wrapper.find("label").text()).toBe("Select all");
    });

    it("generates a unique id when the caller supplies none", () => {
        const first = mount(Checkbox).find("input").attributes("id");
        const second = mount(Checkbox).find("input").attributes("id");

        expect(first).not.toBe(second);
    });

    it("can be disabled", () => {
        expect(inputOf(mount(Checkbox, { props: { refId: "all", disabled: true } })).disabled).toBe(true);
    });

    it("submits 'true' unless told otherwise", () => {
        expect(
            mount(Checkbox, { props: { refId: "all" } })
                .find("input")
                .attributes("value")
        ).toBe("true");
        expect(
            mount(Checkbox, { props: { refId: "all", value: "1" } })
                .find("input")
                .attributes("value")
        ).toBe("1");
    });
});

describe("Checkbox — indeterminate", () => {
    it("is not indeterminate by default", () => {
        expect(inputOf(mount(Checkbox, { props: { refId: "all" } })).indeterminate).toBe(false);
    });

    it("applies the indeterminate state on mount", async () => {
        // There is no HTML attribute for it — it can only be set as a DOM
        // property, which is why the component reaches for a template ref.
        const wrapper = mount(Checkbox, { props: { refId: "all", indeterminate: true } });
        await nextTick();

        expect(inputOf(wrapper).indeterminate).toBe(true);
    });

    it("follows the prop when the parent's selection changes", async () => {
        const wrapper = mount(Checkbox, { props: { refId: "all", indeterminate: false } });

        await wrapper.setProps({ indeterminate: true });
        expect(inputOf(wrapper).indeterminate).toBe(true);

        await wrapper.setProps({ indeterminate: false });
        expect(inputOf(wrapper).indeterminate).toBe(false);
    });
});

describe("Checkbox — interaction", () => {
    it("emits the new state in both directions", async () => {
        const wrapper = mount(Checkbox, { props: { refId: "all" } });

        await wrapper.find("input").setValue(true);
        await wrapper.find("input").setValue(false);

        expect(wrapper.emitted("change")).toEqual([[true], [false]]);
    });

    it("follows a parent-driven change to the checked state", async () => {
        // The select-all header checkbox is driven by the row selection, so it
        // has to reflect changes it did not originate.
        const wrapper = mount(Checkbox, { props: { refId: "all", checkedInitially: false } });

        await wrapper.setProps({ checkedInitially: true });

        expect(inputOf(wrapper).checked).toBe(true);
    });

    it("does not emit when the parent drives the change", async () => {
        const wrapper = mount(Checkbox, { props: { refId: "all", checkedInitially: false } });

        await wrapper.setProps({ checkedInitially: true });

        expect(wrapper.emitted("change")).toBeUndefined();
    });
});
