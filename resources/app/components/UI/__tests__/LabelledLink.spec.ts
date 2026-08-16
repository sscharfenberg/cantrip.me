import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import LabelledLink from "../LabelledLink.vue";

vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());

/** The sprite id of the rendered icon, or null when there is none. */
const iconOf = (wrapper: ReturnType<typeof mount>): string | null =>
    wrapper.find("use").exists() ? (wrapper.find("use").attributes("href") ?? null) : null;

describe("LabelledLink — internal links", () => {
    it("routes through Inertia rather than a full page load", () => {
        const wrapper = mount(LabelledLink, { props: { href: "/decks" }, slots: { default: "Decks" } });

        expect(wrapper.findComponent({ name: "Link" }).exists()).toBe(true);
        expect(wrapper.text()).toBe("Decks");
    });

    it("carries no icon by default", () => {
        expect(iconOf(mount(LabelledLink, { props: { href: "/decks" } }))).toBeNull();
    });

    it("passes the request method and payload through to Inertia", () => {
        const wrapper = mount(LabelledLink, {
            props: { href: "/logout", method: "post", data: { redirect: "/" } }
        });

        expect(wrapper.findComponent({ name: "Link" }).props()).toMatchObject({
            href: "/logout",
            method: "post",
            data: { redirect: "/" }
        });
    });
});

describe("LabelledLink — external links", () => {
    it("renders a plain anchor, since Inertia cannot route off-site", () => {
        const wrapper = mount(LabelledLink, { props: { href: "https://scryfall.com" } });

        expect(wrapper.findComponent({ name: "Link" }).exists()).toBe(false);
        expect(wrapper.find("a").attributes("href")).toBe("https://scryfall.com");
    });

    it("opens in a new tab without leaking the referrer or link equity", () => {
        const wrapper = mount(LabelledLink, { props: { href: "https://scryfall.com" } });

        expect(wrapper.find("a").attributes()).toMatchObject({
            target: "_blank",
            rel: "noopener nofollow"
        });
    });

    it("marks itself with the external-link icon", () => {
        expect(iconOf(mount(LabelledLink, { props: { href: "https://scryfall.com" } }))).toBe("#external-link");
    });

    it("treats plain http as external too", () => {
        const wrapper = mount(LabelledLink, { props: { href: "http://example.test" } });

        expect(wrapper.find("a").attributes("target")).toBe("_blank");
    });
});

describe("LabelledLink — mailto links", () => {
    it("renders a same-tab anchor", () => {
        const wrapper = mount(LabelledLink, { props: { href: "mailto:admin@cantrip.me" } });

        expect(wrapper.find("a").attributes("href")).toBe("mailto:admin@cantrip.me");
        expect(wrapper.find("a").attributes("target")).toBeUndefined();
    });

    it("marks itself with the mail icon", () => {
        expect(iconOf(mount(LabelledLink, { props: { href: "mailto:admin@cantrip.me" } }))).toBe("#mail");
    });
});

describe("LabelledLink — icon override", () => {
    it("uses an explicitly named icon over the inferred one", () => {
        const wrapper = mount(LabelledLink, { props: { href: "https://scryfall.com", icon: "scryfall" } });

        expect(iconOf(wrapper)).toBe("#scryfall");
    });

    it("suppresses the inferred icon on an empty string", () => {
        const wrapper = mount(LabelledLink, { props: { href: "https://scryfall.com", icon: "" } });

        expect(iconOf(wrapper)).toBeNull();
    });

    it("adds an icon to an internal link when one is named", () => {
        expect(iconOf(mount(LabelledLink, { props: { href: "/decks", icon: "deck" } }))).toBe("#deck");
    });
});
