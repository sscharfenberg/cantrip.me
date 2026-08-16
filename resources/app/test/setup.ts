import { config } from "@vue/test-utils";
import { beforeEach } from "vitest";
import type { Directive } from "vue";
import { createTestI18n } from "./i18n.ts";

/******************************************************************************
 * Global Vitest setup — imported once per spec file, before any test in it.
 *
 * Two jobs:
 *  1. Close the gap between jsdom and a real browser for the handful of APIs
 *     this app touches. Components that register an observer in `onMounted`
 *     would otherwise throw on mount, turning every spec for them into a
 *     harness failure rather than a useful assertion.
 *  2. Install the Vue Test Utils defaults every `mount()` inherits, so specs
 *     don't repeat the plugin wiring `main.ts` does at bootstrap.
 *
 * `resources/app/test/__tests__/harness.spec.ts` asserts on everything set up
 * here, so a regression surfaces there rather than in a feature spec.
 *****************************************************************************/

/******************************************************************************
 * jsdom gaps
 *****************************************************************************/

/**
 * No-op stand-in for the two observer APIs jsdom does not implement.
 *
 * Components use them for lazy image loading (`Card/CardSearch/Results.vue`)
 * and sticky-header detection (`DataTable/DataTable.vue`). Nothing under test
 * asserts on intersection callbacks — the class exists so `new
 * IntersectionObserver(...)` in `onMounted` resolves instead of throwing.
 */
class NoopObserver {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
    takeRecords(): [] {
        return [];
    }
}

/**
 * Minimal in-memory `Storage`.
 *
 * Needed because of a three-way collision: Node 26 ships its own `localStorage`
 * global whose getter returns `undefined` unless the process was started with
 * `--localstorage-file`, that global is *not* replaced by Vitest's jsdom
 * environment, and jsdom's `Storage` constructor cannot be invoked directly.
 * Without this shim the app sees a bare `undefined` where every browser has a
 * Storage — `useDeckSort`, `useDeckView` and `useAddCardsDefaults` all read it.
 *
 * Only the documented `Storage` methods are supported; the property-access form
 * (`storage.foo = "bar"`) is not, and no app code uses it.
 */
class MemoryStorage {
    private entries = new Map<string, string>();

    get length(): number {
        return this.entries.size;
    }

    key(index: number): string | null {
        return [...this.entries.keys()][index] ?? null;
    }

    getItem(key: string): string | null {
        return this.entries.get(String(key)) ?? null;
    }

    setItem(key: string, value: string): void {
        this.entries.set(String(key), String(value));
    }

    removeItem(key: string): void {
        this.entries.delete(String(key));
    }

    clear(): void {
        this.entries.clear();
    }
}

/** Overwrite a global unconditionally — plain assignment loses to a getter. */
const defineGlobal = (name: string, value: unknown): void => {
    Object.defineProperty(globalThis, name, { value, configurable: true, writable: true });
};

if (!("IntersectionObserver" in globalThis)) {
    defineGlobal("IntersectionObserver", NoopObserver);
}

if (!("ResizeObserver" in globalThis)) {
    defineGlobal("ResizeObserver", NoopObserver);
}

if (!Element.prototype.scrollIntoView) {
    // jsdom has no layout engine, so scrolling is a no-op by definition.
    Element.prototype.scrollIntoView = function scrollIntoView(): void {};
}

// Installed unconditionally rather than behind an `if (!globalThis.localStorage)`
// guard: merely *reading* the global trips Node's ExperimentalWarning, which
// would then print once per spec file. The shim is what the suite wants in
// either case — deterministic and empty at the start of every test.
defineGlobal("localStorage", new MemoryStorage());
defineGlobal("sessionStorage", new MemoryStorage());

if (!globalThis.matchMedia) {
    /**
     * `matches: false` for every query — i.e. no media preference is active,
     * which is the branch the app treats as normal (animations on). A spec that
     * needs the other branch — `Modal.vue` and `Card/CardImagePreview.vue` both
     * check `prefers-reduced-motion` — should `vi.stubGlobal("matchMedia", …)`
     * itself; `unstubGlobals` in `vitest.config.ts` reverts it afterwards.
     */
    defineGlobal("matchMedia", (query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: () => {},
        removeListener: () => {},
        addEventListener: () => {},
        removeEventListener: () => {},
        dispatchEvent: () => false
    }));
}

/******************************************************************************
 * Vue Test Utils defaults
 *****************************************************************************/

/**
 * Stand-in for FloatingVue's `v-tooltip`, which `main.ts` installs app-wide.
 *
 * Rather than rendering a floating element, the value is written to
 * `data-tooltip` on the host element. Tooltip *content* is frequently real
 * logic in this app (see `Deck/DeckCardCount.vue`, which assembles a multi-line
 * HTML tooltip), so making it assertable via `wrapper.attributes("data-tooltip")`
 * is worth more than a bare no-op.
 */
const tooltipStub: Directive<HTMLElement, string | undefined> = {
    mounted(el, binding) {
        el.dataset.tooltip = binding.value ?? "";
    },
    updated(el, binding) {
        el.dataset.tooltip = binding.value ?? "";
    }
};

beforeEach(() => {
    // A fresh i18n instance per test: a spec that flips the locale, or registers
    // its own messages, must not bleed into the next one.
    config.global.plugins = [createTestI18n()];
    config.global.directives = { tooltip: tooltipStub };

    localStorage.clear();
    sessionStorage.clear();
});
