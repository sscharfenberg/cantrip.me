import { vi } from "vitest";

/**
 * Shared-prop and router doubles for `@inertiajs/vue3`.
 *
 * The real module reaches for a page object that only exists once
 * `createInertiaApp` has bootstrapped, so any composable calling `usePage()`
 * throws under test. Specs replace the module wholesale:
 *
 * ```ts
 * vi.mock("@inertiajs/vue3", async () => (await import("@/test/inertia.ts")).inertiaModuleMock());
 *
 * beforeEach(() => setPageProps({ auth: { user: { deck_sort_default: "name" } } }));
 * ```
 *
 * `vi.mock` is hoisted above the imports, so the factory has to pull this
 * module in dynamically — a static import would not have been evaluated yet.
 */

/** Live object handed to callers as `usePage().props`. Mutated by {@link setPageProps}. */
export const pageProps: Record<string, any> = {};

/**
 * Stand-in for Inertia's `router`. Every method is a bare spy: assert on the
 * call, don't expect a visit to happen. `clearMocks` in `vitest.config.ts`
 * clears the call history between tests — `restoreMocks` would not, since these
 * are plain `vi.fn()`s rather than `vi.spyOn` spies.
 */
export const routerMock = {
    visit: vi.fn(),
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
    on: vi.fn()
};

/**
 * Replace the Inertia shared props seen by `usePage()`.
 *
 * Mutates the existing object rather than rebinding it, because composables
 * capture `usePage()` once during setup and read `.props` later.
 *
 * @param props - The complete new shared-prop set. Anything omitted is dropped.
 */
export function setPageProps(props: Record<string, unknown>): void {
    for (const key of Object.keys(pageProps)) {
        delete pageProps[key];
    }
    Object.assign(pageProps, props);
}

/**
 * Records every `useForm()` created during a test, newest last.
 *
 * The double is deliberately dumb — it holds the fields, exposes the request
 * verbs as spies, and does nothing else. A spec asserting on a form submit
 * should read `formMocks[formMocks.length - 1]`.
 */
export const formMocks: InertiaFormMock[] = [];

/** The shape `useForm()` hands back, reduced to what this app touches. */
export interface InertiaFormMock extends Record<string, unknown> {
    errors: Record<string, string>;
    processing: boolean;
    get: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
    put: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
    submit: ReturnType<typeof vi.fn>;
    reset: ReturnType<typeof vi.fn>;
    clearErrors: ReturnType<typeof vi.fn>;
}

/** Forget every recorded form. Called from the global `beforeEach`. */
export function clearFormMocks(): void {
    formMocks.length = 0;
}

/**
 * The module shape to hand to `vi.mock("@inertiajs/vue3", …)`.
 *
 * `vi.mock` replaces the module wholesale — there is no `importOriginal` here —
 * so anything the app imports from `@inertiajs/vue3` and this object omits
 * fails at import time with "No X export is defined on the mock". The app's
 * full surface is `usePage`, `router`, `Head`, `Link`, `Form` and `useForm`.
 *
 * `Head` renders nothing (it teleports into `<head>` in the real app). `Link`
 * renders the element it stands for, honouring `as` so `wrapper.find("button")`
 * works on a button-shaped link. `Form` renders a `<form>` that swallows the
 * submit rather than navigating.
 */
export function inertiaModuleMock() {
    return {
        usePage: () => ({ props: pageProps }),
        router: routerMock,

        Head: {
            name: "Head",
            setup: () => () => null
        },

        Link: {
            name: "Link",
            props: {
                href: { type: String, default: "" },
                as: { type: String, default: "a" },
                method: { type: String, default: "get" }
            },
            template: `<component :is="as" :href="as === 'a' ? href : undefined" :data-method="method"><slot /></component>`
        },

        Form: {
            name: "Form",
            props: {
                action: { type: String, default: "" },
                method: { type: String, default: "post" }
            },
            template: `<form :action="action" :method="method" @submit.prevent><slot /></form>`
        },

        useForm: (fields: Record<string, unknown> = {}) => {
            const form: InertiaFormMock = {
                ...fields,
                errors: {},
                processing: false,
                get: vi.fn(),
                post: vi.fn(),
                put: vi.fn(),
                patch: vi.fn(),
                delete: vi.fn(),
                submit: vi.fn(),
                reset: vi.fn(),
                clearErrors: vi.fn()
            };
            formMocks.push(form);
            return form;
        }
    };
}
