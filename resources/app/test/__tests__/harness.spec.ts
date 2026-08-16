import { mount } from "@vue/test-utils";
import { beforeAll, describe, expect, it } from "vitest";
import { defineComponent } from "vue";
import { isSubsetCI } from "@/utils/colorIdentity.ts";
import Icon from "Components/UI/Icon.vue";
import { createTestI18n } from "../i18n.ts";

/******************************************************************************
 * Harness regression tests.
 *
 * These assert on the test infrastructure itself, not on app behaviour. They
 * exist so that a broken alias map, a missing jsdom polyfill or a mis-wired
 * i18n default fails here with an obvious message, instead of surfacing as a
 * baffling failure in whichever feature spec happens to run first.
 *****************************************************************************/

describe("harness: module resolution", () => {
    it("resolves the `Components/` alias to a real single-file component", () => {
        expect(Icon).toBeTypeOf("object");
    });

    it("resolves the `@/` alias to app source", () => {
        expect(isSubsetCI(null, "WU")).toBe(true);
    });
});

describe("harness: jsdom environment", () => {
    it("provides a DOM", () => {
        expect(typeof document).toBe("object");
        expect(document.createElement("div")).toBeInstanceOf(HTMLElement);
    });

    it("provides the observer APIs components register on mount", () => {
        expect(() => new IntersectionObserver(() => {})).not.toThrow();
        expect(() => new ResizeObserver(() => {})).not.toThrow();
    });

    it("provides Element.scrollIntoView", () => {
        expect(() => document.createElement("div").scrollIntoView()).not.toThrow();
    });

    it("provides window.matchMedia, which components read for reduced motion", () => {
        expect(window.matchMedia("(prefers-reduced-motion: reduce)")).toHaveProperty("matches");
    });
});

describe("harness: web storage", () => {
    // Seeded once, before the per-test `beforeEach` chain in `setup.ts` has run
    // for the first time. The isolation assertion below therefore holds on its
    // own — it does not depend on some earlier `it` in this file having written
    // to storage first, so running it alone (`-t`) still proves something.
    beforeAll(() => {
        localStorage.setItem("cantrip:leak-check", "seeded");
        sessionStorage.setItem("cantrip:leak-check", "seeded");
    });

    it("is emptied before every test", () => {
        expect(localStorage.length).toBe(0);
        expect(localStorage.getItem("cantrip:leak-check")).toBeNull();
        expect(sessionStorage.getItem("cantrip:leak-check")).toBeNull();
    });

    it("implements the Storage surface the app relies on", () => {
        localStorage.setItem("cantrip:deck-sort:deck-1", "name");

        expect(localStorage.length).toBe(1);
        expect(localStorage.key(0)).toBe("cantrip:deck-sort:deck-1");
        expect(localStorage.getItem("cantrip:deck-sort:deck-1")).toBe("name");

        localStorage.removeItem("cantrip:deck-sort:deck-1");

        expect(localStorage.getItem("cantrip:deck-sort:deck-1")).toBeNull();
        expect(localStorage.length).toBe(0);
    });
});

describe("harness: single-file component compilation", () => {
    it("mounts an SFC that carries a scoped SCSS block", () => {
        const wrapper = mount(Icon, { props: { name: "key", size: 3 } });

        expect(wrapper.find("svg").classes()).toContain("key");
        // Vue sets `xlink:href` through the XLink namespace, so Vue Test Utils
        // reports it under its local name.
        expect(wrapper.find("use").attributes("href")).toBe("#key");
    });

    it("applies scoped-style data attributes, proving the SFC style block compiled", () => {
        const attributes = Object.keys(mount(Icon, { props: { name: "key" } }).attributes());

        expect(attributes.some(name => name.startsWith("data-v-"))).toBe(true);
    });
});

describe("harness: i18n defaults", () => {
    const TranslatingComponent = defineComponent({
        template: `<p>{{ $t("pages.login.title") }}</p>`
    });

    it("echoes the translation key when no messages are registered", () => {
        expect(mount(TranslatingComponent).text()).toBe("pages.login.title");
    });

    it("uses real messages when a spec supplies its own i18n instance", () => {
        const wrapper = mount(TranslatingComponent, {
            global: { plugins: [createTestI18n({ de: { pages: { login: { title: "Anmelden" } } } })] }
        });

        expect(wrapper.text()).toBe("Anmelden");
    });
});

describe("harness: global directives", () => {
    const TooltipComponent = defineComponent({
        template: `<span v-tooltip="'Kartenanzahl'">42</span>`
    });

    it("stubs v-tooltip and exposes its value as data-tooltip", () => {
        expect(mount(TooltipComponent).attributes("data-tooltip")).toBe("Kartenanzahl");
    });
});
