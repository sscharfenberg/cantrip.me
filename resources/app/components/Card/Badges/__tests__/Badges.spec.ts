import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import type { Component } from "vue";
import GameChangerBadge from "../GameChangerBadge.vue";
import MassLandDenialBadge from "../MassLandDenialBadge.vue";
import ProxyBadge from "../ProxyBadge.vue";

/******************************************************************************
 * The three card badges share one shape — icon-only with a tooltip by default,
 * icon-plus-label when the surrounding layout has room — so they are covered
 * together. A divergence between them is exactly what this file should catch.
 *****************************************************************************/

const BADGES: [name: string, component: Component, icon: string, labelKey: string, tooltipKey: string][] = [
    ["GameChangerBadge", GameChangerBadge, "#balance", "components.badges.game_changer", "pages.deck.game_changer"],
    ["MassLandDenialBadge", MassLandDenialBadge, "#landslide", "components.badges.mass_land_denial", "pages.deck.mld"],
    // The only one that reuses a single key for both — its label already is the
    // whole explanation.
    ["ProxyBadge", ProxyBadge, "#print", "form.fields.proxy.label", "form.fields.proxy.label"]
];

describe.each(BADGES)("%s", (_name, component, icon, labelKey, tooltipKey) => {
    it("renders its own icon", () => {
        expect(mount(component).find("use").attributes("href")).toBe(icon);
    });

    it("is icon-only by default, with the long-form explanation in the tooltip", () => {
        const wrapper = mount(component);

        expect(wrapper.text()).toBe("");
        expect(wrapper.attributes("data-tooltip")).toBe(tooltipKey);
    });

    it("shows the label when the layout has room for it", () => {
        const wrapper = mount(component, { props: { showLabel: true } });

        expect(wrapper.text()).toBe(labelKey);
    });

    it("drops the tooltip once the label is visible, so the text is not doubled", () => {
        const wrapper = mount(component, { props: { showLabel: true } });

        expect(wrapper.attributes("data-tooltip")).toBeUndefined();
    });

    it("is a badge, so it inherits the shared badge styling", () => {
        expect(mount(component).classes()).toContain("badge");
    });
});

describe("badge identity", () => {
    it("gives each badge a distinct icon and root class", () => {
        const icons = BADGES.map(([, component]) => mount(component).find("use").attributes("href"));
        const classes = BADGES.map(([, component]) =>
            mount(component)
                .classes()
                .find(name => name.endsWith("-badge"))
        );

        expect(new Set(icons).size).toBe(BADGES.length);
        expect(new Set(classes).size).toBe(BADGES.length);
    });
});
