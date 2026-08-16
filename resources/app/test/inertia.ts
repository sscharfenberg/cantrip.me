import { vi } from "vitest";
import { h, reactive } from "vue";
import type { VNode } from "vue";

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

/**
 * Live object handed to callers as `usePage().props`. Mutated by
 * {@link setPageProps}.
 *
 * Reactive, because the real Inertia page props are: components `watch` them to
 * react to a new response — `UI/ToastContainer.vue` raises a toast from
 * `flash.nonce` that way — and a plain object would leave every such watcher
 * silent, so the spec would pass while the feature was broken.
 */
export const pageProps: Record<string, any> = reactive({});

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
 * Records every `useForm()` and `<Form>` created during a test, newest last.
 *
 * The double holds the fields, exposes the request verbs as spies, and does
 * nothing else. A spec asserting on a submit should read
 * `formMocks[formMocks.length - 1]`.
 */
export const formMocks: InertiaFormMock[] = [];

/** The shape `useForm()` hands back, reduced to what this app touches. */
export interface InertiaFormMock extends Record<string, unknown> {
    errors: Record<string, string>;
    hasErrors: boolean;
    processing: boolean;
    isDirty: boolean;
    wasSuccessful: boolean;
    recentlySuccessful: boolean;
    progress: null;
    get: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
    put: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
    submit: ReturnType<typeof vi.fn>;
    reset: ReturnType<typeof vi.fn>;
    clearErrors: ReturnType<typeof vi.fn>;
    setError: ReturnType<typeof vi.fn>;
    transform: ReturnType<typeof vi.fn>;
    defaults: ReturnType<typeof vi.fn>;
    cancel: ReturnType<typeof vi.fn>;
}

/**
 * Build one form double.
 *
 * Reactive for the same reason {@link pageProps} is: components bind
 * `v-model="form.name"` and render off `form.processing` / `form.errors`, so a
 * plain object would leave the DOM frozen while the spec passed.
 */
function createFormMock(fields: Record<string, unknown> = {}): InertiaFormMock {
    const form = reactive({
        ...fields,
        errors: {} as Record<string, string>,
        hasErrors: false,
        processing: false,
        isDirty: false,
        wasSuccessful: false,
        recentlySuccessful: false,
        progress: null,
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        submit: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
        setError: vi.fn(),
        transform: vi.fn(),
        defaults: vi.fn(),
        cancel: vi.fn()
    }) as InertiaFormMock;

    formMocks.push(form);
    return form;
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
 * fails at import time with "No X export is defined on the mock". Page and
 * component code imports `usePage`, `router`, `Head`, `Link`, `Form` and
 * `useForm`; `main.ts` also imports `createInertiaApp`, but it is never mounted
 * under test.
 *
 * `Head` renders nothing (it teleports into `<head>` in the real app). `Link`
 * renders the element it stands for, honouring `as` so `wrapper.find("button")`
 * works on a button-shaped link. `Form` renders a `<form>` that swallows the
 * submit rather than navigating, and — crucially — hands its default slot the
 * payload the real component does: every `<Form>` in this app destructures
 * `{ errors, processing, … }`, and a bare `<slot />` makes those `undefined`,
 * so the page throws on render before any assertion runs.
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
                method: { type: String, default: "get" },
                data: { type: Object, default: undefined }
            },
            template: `<component :is="as" :href="as === 'a' ? href : undefined" :data-method="method"><slot /></component>`
        },

        Form: {
            name: "Form",
            props: {
                action: { type: String, default: "" },
                method: { type: String, default: "post" }
            },
            setup(_props: unknown, { slots }: { slots: Record<string, ((payload: unknown) => VNode[]) | undefined> }) {
                const form = createFormMock();
                const payload = {
                    errors: form.errors,
                    hasErrors: form.hasErrors,
                    processing: form.processing,
                    validating: false,
                    wasSuccessful: form.wasSuccessful,
                    recentlySuccessful: form.recentlySuccessful,
                    progress: null,
                    isDirty: form.isDirty,
                    valid: () => false,
                    invalid: () => false,
                    validate: vi.fn(),
                    touch: vi.fn(),
                    reset: form.reset,
                    clearErrors: form.clearErrors,
                    setError: form.setError,
                    defaults: form.defaults,
                    submit: form.submit
                };

                return () =>
                    h("form", { onSubmit: (event: Event) => event.preventDefault() }, slots.default?.(payload));
            }
        },

        useForm: (fields: Record<string, unknown> = {}) => createFormMock(fields)
    };
}
