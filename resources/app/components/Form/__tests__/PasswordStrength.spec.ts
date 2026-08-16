import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { defineComponent, h } from "vue";
import PasswordStrength from "../PasswordStrength.vue";

/******************************************************************************
 * Note on what is *not* covered here.
 *
 * The fill width is published to CSS with `v-bind(barWidth)` inside the
 * `<style>` block. That binding is compiled away in this harness — neither
 * `css: false` nor `css: true` makes `@vitejs/plugin-vue` emit the custom
 * property under Vitest — so the rendered bar carries no inline style to
 * assert on. `aria-valuenow` carries the same number and is covered below.
 *****************************************************************************/

describe("PasswordStrength — meter", () => {
    it("exposes the score to assistive tech over the zxcvbn range", () => {
        const meter = mount(PasswordStrength, { props: { score: 2 } }).find('[role="meter"]');

        expect(meter.attributes()).toMatchObject({
            "aria-valuemin": "0",
            "aria-valuemax": "4",
            "aria-valuenow": "2"
        });
    });

    it.each([0, 1, 2, 3, 4])("reports score %i verbatim", score => {
        const meter = mount(PasswordStrength, { props: { score } }).find('[role="meter"]');

        expect(meter.attributes("aria-valuenow")).toBe(String(score));
    });

    it("renders the masking bar the fill width is applied to", () => {
        expect(
            mount(PasswordStrength, { props: { score: 2 } })
                .find(".password-strength__bar")
                .exists()
        ).toBe(true);
    });

    it("labels the meter for screen readers", () => {
        const meter = mount(PasswordStrength, { props: { score: 2 } }).find('[role="meter"]');

        expect(meter.attributes("aria-label")).toBe("Password Strength");
    });
});

describe("PasswordStrength — validity indicator", () => {
    it.each([0, 1, 2])("warns at score %i, which the backend rejects", score => {
        const wrapper = mount(PasswordStrength, { props: { score } });

        expect(wrapper.find(".form-group--invalid").exists()).toBe(true);
        expect(wrapper.find(".form-group--valid").exists()).toBe(false);
    });

    it.each([3, 4])("passes at score %i", score => {
        const wrapper = mount(PasswordStrength, { props: { score } });

        expect(wrapper.find(".form-group--valid").exists()).toBe(true);
        expect(wrapper.find(".form-group--invalid").exists()).toBe(false);
    });

    it("shows exactly one indicator, never both or neither", () => {
        for (const score of [0, 1, 2, 3, 4]) {
            const wrapper = mount(PasswordStrength, { props: { score } });

            expect(wrapper.findAll(".form-group--valid, .form-group--invalid")).toHaveLength(1);
        }
    });
});

describe("PasswordStrength — anchoring", () => {
    it("gives each instance on a page its own CSS anchor name", () => {
        // Two password fields on the registration form would otherwise anchor
        // their indicators to the same meter. Both are mounted inside one
        // parent because `useId` counts per app — two separate `mount()` calls
        // are two apps and would each start from the same id.
        const TwoFields = defineComponent({
            render: () => h("div", [h(PasswordStrength, { score: 2 }), h(PasswordStrength, { score: 4 })])
        });

        const anchors = mount(TwoFields)
            .findAll(".password-strength__meter")
            .map(meter => meter.attributes("style"));

        expect(anchors).toHaveLength(2);
        expect(anchors[0]).toMatch(/anchor-name: --psm-/);
        expect(anchors[0]).not.toBe(anchors[1]);
    });

    it("points the indicator at this instance's own meter", () => {
        const wrapper = mount(PasswordStrength, { props: { score: 4 } });
        const anchorName = /anchor-name: (--psm-[^;"]+)/.exec(
            wrapper.find(".password-strength__meter").attributes("style") ?? ""
        )?.[1];

        expect(anchorName).toBeTruthy();
        expect(wrapper.find(".form-group--valid").attributes("style")).toContain(`position-anchor: ${anchorName}`);
    });
});
