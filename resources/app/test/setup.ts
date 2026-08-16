import { config, enableAutoUnmount } from "@vue/test-utils";
import { afterEach, beforeEach } from "vitest";
import type { Directive } from "vue";
import { createTestI18n } from "./i18n.ts";
import { clearFormMocks, setPageProps } from "./inertia.ts";
import { FakeIntersectionObserver, FakeResizeObserver, clearRecordedObservers } from "./observers.ts";

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

// jsdom implements neither observer. The fakes record every instance so a spec
// can drive the callback directly — see `resources/app/test/observers.ts`.
defineGlobal("IntersectionObserver", FakeIntersectionObserver);
defineGlobal("ResizeObserver", FakeResizeObserver);

if (!Element.prototype.scrollIntoView) {
    // jsdom has no layout engine, so scrolling is a no-op by definition.
    Element.prototype.scrollIntoView = function scrollIntoView(): void {};
}

/**
 * jsdom never runs CSS, but it does dispatch whatever a test constructs — which
 * is how a component that sequences behaviour on an animation finishing gets
 * driven at all. `Modal.vue` defers `close()` until its exit animation ends;
 * `UI/Accordion.vue` clears its inline heights on `transitionend`.
 *
 * jsdom 30 ships `TransitionEvent` but not `AnimationEvent`, so in practice
 * only the first of these two is installed. Both are written out because the
 * gap is a jsdom implementation detail that has moved before and can move
 * again; the `in globalThis` guards mean the native wins whenever it exists.
 * Extra fields are carried through so a handler filtering on `propertyName`
 * behaves as it would in a browser.
 */
class StubAnimationEvent extends Event {
    readonly animationName: string;
    readonly elapsedTime: number;

    constructor(type: string, init: EventInit & { animationName?: string; elapsedTime?: number } = {}) {
        super(type, init);
        this.animationName = init.animationName ?? "";
        this.elapsedTime = init.elapsedTime ?? 0;
    }
}

class StubTransitionEvent extends Event {
    readonly propertyName: string;
    readonly elapsedTime: number;

    constructor(type: string, init: EventInit & { propertyName?: string; elapsedTime?: number } = {}) {
        super(type, init);
        this.propertyName = init.propertyName ?? "";
        this.elapsedTime = init.elapsedTime ?? 0;
    }
}

/**
 * jsdom parses `<dialog>` but implements none of its behaviour, so
 * `showModal()` / `close()` are missing entirely and any component that opens a
 * modal on mount throws before it renders.
 *
 * These keep the `open` flag consistent, which is all the app reads. A spec
 * that wants to assert on the calls should spy over the top and restore
 * afterwards — see `components/Modal/__tests__/Modal.spec.ts`.
 */
if (!HTMLDialogElement.prototype.showModal) {
    HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
        this.open = true;
    };
}

if (!HTMLDialogElement.prototype.close) {
    HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
        this.open = false;
    };
}

/**
 * Popover API — also parsed but not implemented.
 *
 * Unlike the dialog stubs above, these are pure no-ops holding no visibility
 * state: nothing in the app reads a popover's open-ness back, it only calls
 * `hidePopover()` to dismiss its own menu. `togglePopover`'s return value is
 * therefore not meaningful. A spec that wants to assert the call happened
 * should shadow `hidePopover` with a spy of its own.
 */
if (!HTMLElement.prototype.hidePopover) {
    HTMLElement.prototype.hidePopover = function hidePopover(): void {};
}

if (!HTMLElement.prototype.showPopover) {
    HTMLElement.prototype.showPopover = function showPopover(): void {};
}

if (!HTMLElement.prototype.togglePopover) {
    HTMLElement.prototype.togglePopover = function togglePopover(): boolean {
        return false;
    };
}

if (!("AnimationEvent" in globalThis)) {
    defineGlobal("AnimationEvent", StubAnimationEvent);
}

if (!("TransitionEvent" in globalThis)) {
    defineGlobal("TransitionEvent", StubTransitionEvent);
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

/** The shapes `v-tooltip` accepts: content, an options object, or `false` to disable. */
type TooltipValue = string | false | null | undefined | { content?: unknown };

/**
 * Resolve a `v-tooltip` binding to the text FloatingVue would show, or null
 * when the directive is disabled.
 *
 * `false` is FloatingVue's "no tooltip" — several components pass
 * `showLabel ? false : t(…)` so the explanation appears either as a visible
 * label or as a tooltip, never both. The object form carries `content` plus
 * placement options (`container: "#modal-body"` where a tooltip has to escape
 * a modal's stacking context).
 */
const tooltipContent = (value: TooltipValue): string | null => {
    if (value === false || value === null || value === undefined) return null;
    if (typeof value === "object") {
        return value.content === undefined ? null : String(value.content);
    }
    return String(value);
};

/**
 * Stand-in for FloatingVue's `v-tooltip`, which `main.ts` installs app-wide.
 *
 * Rather than rendering a floating element, the resolved content is written to
 * `data-tooltip` on the host element, and the attribute is absent when the
 * directive is disabled. Tooltip *content* is frequently real logic in this app
 * — see `Deck/DeckCardCount.vue`, which assembles a multi-line HTML tooltip —
 * so making it assertable is worth more than a bare no-op.
 */
const tooltipStub: Directive<HTMLElement, TooltipValue> = {
    mounted(el, binding) {
        applyTooltip(el, binding.value);
    },
    updated(el, binding) {
        applyTooltip(el, binding.value);
    }
};

const applyTooltip = (el: HTMLElement, value: TooltipValue): void => {
    const content = tooltipContent(value);
    if (content === null) {
        delete el.dataset.tooltip;
    } else {
        el.dataset.tooltip = content;
    }
};

/*
 * Unmount every component a test mounted, once it ends.
 *
 * Without this a mounted component lives on: its watchers keep firing, and a
 * component that reacts to Inertia's shared props — `UI/ToastContainer.vue`
 * watches `flash.nonce` — would respond once per leftover instance, so a spec
 * would see one toast per test that had run before it.
 */
enableAutoUnmount(afterEach);

beforeEach(() => {
    // A fresh i18n instance per test: a spec that flips the locale, or registers
    // its own messages, must not bleed into the next one.
    config.global.plugins = [createTestI18n()];
    config.global.directives = { tooltip: tooltipStub };

    localStorage.clear();
    sessionStorage.clear();
    clearRecordedObservers();

    // Inertia's doubles are plain module state, which none of Vitest's
    // clearMocks / restoreMocks / unstubGlobals reach. Without this a spec that
    // forgets its own `setPageProps` silently inherits the previous test's
    // shared props — and passes for the wrong reason.
    setPageProps({});
    clearFormMocks();
});
