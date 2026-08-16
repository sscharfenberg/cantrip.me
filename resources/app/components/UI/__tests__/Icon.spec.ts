import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import Icon from "../Icon.vue";

/** Classes on the rendered `<svg>`. */
const classesOf = (props: Record<string, unknown>): string[] => mount(Icon, { props }).classes();

describe("Icon", () => {
    it("references the sprite entry by name", () => {
        const wrapper = mount(Icon, { props: { name: "key" } });

        // Vue sets `xlink:href` through the XLink namespace, so Vue Test Utils
        // reports it under its local name.
        expect(wrapper.find("use").attributes("href")).toBe("#key");
    });

    it("carries the icon name as a class, which the stylesheet targets", () => {
        expect(classesOf({ name: "key" })).toContain("key");
    });

    it.each([
        [0, "tiny"],
        [1, "small"],
        [2, "medium"],
        [3, "large"],
        [4, "xlarge"],
        [5, "max"]
    ])("maps size %i to the %s class", (size, expected) => {
        expect(classesOf({ name: "key", size })).toContain(expected);
    });

    it("defaults to the medium size", () => {
        expect(classesOf({ name: "key" })).toContain("medium");
    });

    it("adds the rotate class only when asked", () => {
        expect(classesOf({ name: "key" })).not.toContain("rotate");
        expect(classesOf({ name: "key", rotate: true })).toContain("rotate");
    });

    it("merges caller-supplied classes in", () => {
        expect(classesOf({ name: "key", additionalClasses: ["collection-status", "corner"] })).toEqual(
            expect.arrayContaining(["icon", "collection-status", "corner", "medium", "key"])
        );
    });

    it("does not repeat the base class when a caller passes it again", () => {
        const classes = classesOf({ name: "key", additionalClasses: ["icon"] });

        expect(classes.filter(name => name === "icon")).toHaveLength(1);
    });
});
