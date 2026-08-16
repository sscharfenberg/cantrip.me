import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import Badge from "../Badge.vue";

type BadgeType = "success" | "warning" | "error" | "info" | "caution";

describe("Badge", () => {
    it("renders its slot content", () => {
        expect(mount(Badge, { slots: { default: "Commander" } }).text()).toBe("Commander");
    });

    it("defaults to the informational variant", () => {
        expect(mount(Badge).classes()).toContain("info");
    });

    it.each<BadgeType>(["success", "warning", "error", "info", "caution"])(
        "carries the %s variant as a class",
        type => {
            expect(mount(Badge, { props: { type } }).classes()).toContain(type);
        }
    );
});
