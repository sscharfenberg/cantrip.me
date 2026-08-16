import { mount } from "@vue/test-utils";
import { beforeAll, describe, expect, it } from "vitest";
import { defineComponent, getCurrentInstance, h, nextTick, onMounted, onUnmounted, watch } from "vue";
import { useI18n } from "vue-i18n";
import { isSubsetCI } from "@/utils/colorIdentity.ts";
import Icon from "Components/UI/Icon.vue";
import { createTestI18n } from "../i18n.ts";
import { inertiaModuleMock, pageProps, routerMock, setPageProps } from "../inertia.ts";
import { intersectionObservers, resizeObservers } from "../observers.ts";
import { withSetup } from "../withSetup.ts";

/** Flipped by a `withSetup` app's `onUnmounted`; see the teardown pair below. */
let unmountedByTeardown = false;

/** Live count of mounted throwaway components; see the cleanup pair below. */
let mountedInstances = 0;

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

    it("reads the content out of the options form", () => {
        // Used where a tooltip has to escape a modal's stacking context.
        const WithOptions = defineComponent({
            template: `<span v-tooltip="{ content: 'Gebannt', container: '#modal-body' }">x</span>`
        });

        expect(mount(WithOptions).attributes("data-tooltip")).toBe("Gebannt");
    });

    it("renders no attribute at all when the directive is disabled", () => {
        // `v-tooltip="false"` is how the badges suppress the tooltip once the
        // label is visible; an empty string would read as "tooltip with no text".
        const Disabled = defineComponent({ template: `<span v-tooltip="false">x</span>` });

        expect(mount(Disabled).attributes("data-tooltip")).toBeUndefined();
    });

    it("removes the attribute when a live binding switches to disabled", () => {
        // The runtime path when `showLabel` flips on an already-mounted badge.
        const Toggling = defineComponent({
            props: { tip: { type: [String, Boolean], default: "Kartenanzahl" } },
            template: `<span v-tooltip="tip">x</span>`
        });
        const wrapper = mount(Toggling);

        expect(wrapper.attributes("data-tooltip")).toBe("Kartenanzahl");

        return wrapper.setProps({ tip: false }).then(() => {
            expect(wrapper.attributes("data-tooltip")).toBeUndefined();
        });
    });
});

describe("harness: animation and transition events", () => {
    it("can construct both, whether or not jsdom provides them", () => {
        expect(() => new AnimationEvent("animationend")).not.toThrow();
        expect(() => new TransitionEvent("transitionend")).not.toThrow();
    });

    it("carries the fields handlers filter on", () => {
        const animation = new AnimationEvent("animationend", { animationName: "fade-out" });
        const transition = new TransitionEvent("transitionend", { propertyName: "height" });

        expect(animation.animationName).toBe("fade-out");
        expect(transition.propertyName).toBe("height");
    });

    it("dispatches to a listener like any other event", () => {
        const element = document.createElement("div");
        let seen: string | null = null;
        element.addEventListener("transitionend", event => {
            seen = (event as TransitionEvent).propertyName;
        });

        element.dispatchEvent(new TransitionEvent("transitionend", { propertyName: "height" }));

        expect(seen).toBe("height");
    });
});

describe("harness: mounted components are cleaned up", () => {
    const Counter = defineComponent({
        setup() {
            mountedInstances += 1;
            onUnmounted(() => {
                mountedInstances -= 1;
            });
            return () => null;
        }
    });

    it("leaves a mounted component alive for the length of its own test", () => {
        mount(Counter);
        mount(Counter);

        expect(mountedInstances).toBe(2);
    });

    it("has unmounted the previous test's components by now", () => {
        // Without this, a component watching Inertia's shared props would react
        // once per test that had run before — see `UI/ToastContainer.vue`.
        expect(mountedInstances).toBe(0);
    });
});

describe("harness: Inertia shared props are reactive", () => {
    it("re-runs a watcher when a spec replaces the props", async () => {
        // The real page props are reactive; a plain object would leave every
        // `watch(() => page.props.…)` in the app silent under test.
        const seen: unknown[] = [];
        withSetup(() => {
            watch(
                () => pageProps.flash?.nonce,
                nonce => seen.push(nonce)
            );
            return null;
        });

        setPageProps({ flash: { nonce: "n1" } });
        await nextTick();

        expect(seen).toEqual(["n1"]);
    });

    it("re-renders a component that reads them", async () => {
        setPageProps({ currency: "eur" });
        const Currency = defineComponent({
            setup: () => () => h("span", String(pageProps.currency))
        });
        const wrapper = mount(Currency);

        setPageProps({ currency: "usd" });
        await nextTick();

        expect(wrapper.text()).toBe("usd");
    });
});

describe("harness: recorded observers", () => {
    /** Mount something that registers a ResizeObserver on a real element. */
    const observing = () => {
        const element = document.createElement("div");
        const seen: number[] = [];
        const [, app] = withSetup(() => {
            const observer = new ResizeObserver(entries => {
                seen.push(entries[0].contentBoxSize[0].inlineSize);
            });
            observer.observe(element);
            onUnmounted(() => observer.disconnect());
            return null;
        });
        return { seen, app, element };
    };

    it("records each observer as it is constructed", () => {
        const { element } = observing();

        expect(resizeObservers).toHaveLength(1);
        expect(resizeObservers[0].targets).toEqual([element]);
    });

    it("drives the callback from trigger()", () => {
        const { seen } = observing();

        resizeObservers[0].trigger({ inlineSize: 1200 });

        expect(seen).toEqual([1200]);
    });

    it("marks an observer disconnected when its component unmounts", () => {
        const { app } = observing();

        app.unmount();

        expect(resizeObservers[0].disconnected).toBe(true);
    });

    it("refuses to fire a disconnected observer rather than pretending", () => {
        // A silent no-op here would let a "nothing happens after unmount" test
        // pass without ever exercising anything.
        const { app } = observing();
        app.unmount();

        expect(() => resizeObservers[0].trigger({ inlineSize: 1200 })).toThrow(/after disconnect/);
    });

    it("refuses to fire an observer that is watching nothing", () => {
        withSetup(() => {
            new IntersectionObserver(() => {});
            return null;
        });

        expect(() => intersectionObservers[0].trigger([])).toThrow(/nothing is observed/);
    });

    it("starts each test with an empty registry", () => {
        expect(resizeObservers).toEqual([]);
        expect(intersectionObservers).toEqual([]);
        observing();
    });

    it("does not carry observers over from the previous test", () => {
        expect(resizeObservers).toEqual([]);
    });
});

describe("harness: withSetup", () => {
    it("runs the composable inside a component instance", () => {
        const [result] = withSetup(() => {
            expect(getCurrentInstance()).not.toBeNull();
            return "value";
        });

        expect(result).toBe("value");
    });

    it("fires onMounted", () => {
        let mounted = false;
        withSetup(() => {
            onMounted(() => {
                mounted = true;
            });
            return null;
        });

        expect(mounted).toBe(true);
    });

    it("installs the plugins it is given, which VTU's global config does not reach", () => {
        const [locale] = withSetup(() => useI18n().locale.value, [createTestI18n()]);

        expect(locale).toBe("de");
    });

    it("unmounts leftover apps after each test, firing onUnmounted", () => {
        withSetup(() => {
            onUnmounted(() => {
                unmountedByTeardown = true;
            });
            return null;
        });

        expect(unmountedByTeardown).toBe(false);
    });

    it("has run the previous test's teardown by now", () => {
        expect(unmountedByTeardown).toBe(true);
    });
});

describe("harness: Inertia doubles", () => {
    it("serves the shared props a spec sets", () => {
        setPageProps({ csrfToken: "csrf-token" });

        expect(pageProps).toEqual({ csrfToken: "csrf-token" });
        pageProps.leaked = true;
    });

    it("starts each test with empty shared props", () => {
        // Module state that none of Vitest's mock-reset options can reach; the
        // global setup blanks it instead.
        expect(pageProps).toEqual({});
    });

    it("resets the router spies between tests", () => {
        expect(routerMock.visit).not.toHaveBeenCalled();
        routerMock.visit("/decks");
    });

    it("does not carry router calls over from the previous test", () => {
        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it("exports every symbol the app imports from @inertiajs/vue3", () => {
        // `vi.mock` replaces the module wholesale, so a missing export is an
        // import-time crash in whichever page component uses it.
        expect(Object.keys(inertiaModuleMock()).sort()).toEqual([
            "Form",
            "Head",
            "Link",
            "router",
            "useForm",
            "usePage"
        ]);
    });
});
