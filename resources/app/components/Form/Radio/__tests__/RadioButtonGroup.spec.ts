import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { setTestMessages } from "@/test/i18n.ts";
import RadioButton from "../RadioButton.vue";
import RadioButtonGroup from "../RadioButtonGroup.vue";

const OPTIONS = [
    { value: "2fa", label: "pages.login.2fa.toggle.2fa", checked: true },
    { value: "recovery", label: "pages.login.2fa.toggle.recovery", checked: false }
];

const renderGroup = (props: Record<string, unknown> = {}) =>
    mount(RadioButtonGroup, { props: { name: "type", radioButtons: OPTIONS, ...props } });

describe("RadioButton", () => {
    const render = (props: Record<string, unknown> = {}) =>
        mount(RadioButton, { props: { value: "recovery", name: "type", checked: false, ...props } });

    it("derives a unique id from the group name and the option value", () => {
        // Two groups on one page must not collide, and the label must point at
        // its own input.
        const wrapper = render();

        expect(wrapper.find("input").attributes("id")).toBe("type_recovery");
        expect(wrapper.find("label").attributes("for")).toBe("type_recovery");
    });

    it("shares the group name so the browser treats the options as one set", () => {
        expect(render().find("input").attributes("name")).toBe("type");
    });

    it("reflects the checked state", () => {
        expect((render({ checked: true }).find("input").element as HTMLInputElement).checked).toBe(true);
        expect((render({ checked: false }).find("input").element as HTMLInputElement).checked).toBe(false);
    });

    it("omits the label element entirely when there is no text", () => {
        expect(render().find(".form-radio__label").exists()).toBe(false);
    });

    it("shows the label when given one", () => {
        expect(render({ label: "Recovery code" }).find(".form-radio__label").text()).toBe("Recovery code");
    });

    it("shows an icon only alongside a label", () => {
        expect(render({ icon: "key" }).find("use").exists()).toBe(false);
        expect(render({ label: "Recovery code", icon: "key" }).find("use").attributes("href")).toBe("#key");
    });

    it("forwards the native change event", async () => {
        const wrapper = render();

        await wrapper.find("input").trigger("change");

        expect(wrapper.emitted("change")).toHaveLength(1);
    });
});

describe("RadioButtonGroup", () => {
    it("renders one option per entry", () => {
        expect(renderGroup().findAllComponents(RadioButton)).toHaveLength(2);
    });

    it("translates each label before handing it to the option", () => {
        // Real messages, because the default key echo would make a group that
        // forwarded the raw key look identical to one that translated it.
        setTestMessages({
            de: { pages: { login: { "2fa": { toggle: { "2fa": "Authenticator", recovery: "Wiederherstellung" } } } } }
        });
        const wrapper = mount(RadioButtonGroup, { props: { name: "type", radioButtons: OPTIONS } });

        expect(wrapper.findAllComponents(RadioButton).map(option => option.props("label"))).toEqual([
            "Authenticator",
            "Wiederherstellung"
        ]);
    });

    it("passes the shared name down to every option", () => {
        const names = renderGroup()
            .findAllComponents(RadioButton)
            .map(option => option.props("name"));

        expect(names).toEqual(["type", "type"]);
    });

    it("marks the pre-selected option", () => {
        const checked = renderGroup()
            .findAllComponents(RadioButton)
            .map(option => option.props("checked"));

        expect(checked).toEqual([true, false]);
    });

    it("stacks vertically unless told otherwise", () => {
        expect(renderGroup().find("ul").classes()).toContain("radio-group--column");
    });

    it("lays out in a row on request", () => {
        expect(renderGroup({ layout: "row" }).find("ul").classes()).toContain("radio-group--row");
    });

    it("bubbles a child's change event to the parent form", async () => {
        const wrapper = renderGroup();

        await wrapper.findAll("input")[1].trigger("change");

        expect(wrapper.emitted("change")).toHaveLength(1);
    });

    it("exposes itself as a labelled list to assistive tech", () => {
        const list = renderGroup().find("ul");

        expect(list.attributes("role")).toBe("list");
        expect(list.attributes("aria-label")).toBe("components.radio.label");
    });

    it("renders an empty list rather than failing when given no options", () => {
        const wrapper = mount(RadioButtonGroup, { props: { name: "type", radioButtons: [] } });

        expect(wrapper.findAllComponents(RadioButton)).toHaveLength(0);
    });
});
