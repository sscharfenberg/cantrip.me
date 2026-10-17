import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import InfoBox from "../InfoBox.vue";

describe("InfoBox", () => {
    it("is announced as a note, with the info icon hidden from assistive tech", () => {
        const wrapper = mount(InfoBox, { slots: { default: "<p>How this is counted.</p>" } });

        expect(wrapper.attributes("role")).toBe("note");
        const icon = wrapper.get("svg.icon");
        expect(icon.classes()).toContain("info");
        expect(icon.attributes("aria-hidden")).toBe("true");
    });

    it("holds several paragraphs, not just one", () => {
        const wrapper = mount(InfoBox, { slots: { default: "<p>First.</p><p>Second.</p>" } });

        expect(wrapper.findAll(".info-box__body > p").map(p => p.text())).toEqual(["First.", "Second."]);
    });
});
