import { afterEach } from "vitest";
import { createApp } from "vue";
import type { App, Plugin } from "vue";

/** Apps mounted during the current test, drained after it. */
const mounted: App<Element>[] = [];

afterEach(() => {
    // Unmounting is not just tidiness: it is what fires `onUnmounted`, where
    // several composables disconnect their observers. Doing it here means a
    // spec cannot forget, and the teardown path gets exercised on every test
    // rather than only the ones that thought to check it.
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }
});

/**
 * Run a composable inside a real component instance.
 *
 * Composables that call `provide`, `inject`, `onMounted` or `onUnmounted` need
 * an active instance; calling them bare throws or silently skips the lifecycle
 * hook. This mounts a throwaway component whose only job is to invoke the
 * composable and hand back its return value.
 *
 * The app is unmounted automatically when the test ends. Call `unmount()`
 * yourself only when the test is *about* teardown:
 *
 * ```ts
 * const [nav, app] = withSetup(() => useStickyNav(["a", "b"]));
 * app.unmount();
 * expect(observer.disconnected).toBe(true);
 * ```
 *
 * Vue Test Utils' `config.global.plugins` does not reach this app — that is
 * VTU's `mount()`, and this deliberately is not — so a composable calling
 * `useI18n()` needs its i18n instance passed in explicitly.
 *
 * @param composable - Called inside `setup()`; its return value is passed back.
 * @param plugins - Plugins to install before mounting (e.g. `createTestI18n()`).
 * @return The composable's return value and the app hosting it.
 */
export function withSetup<T>(composable: () => T, plugins: Plugin[] = []): [T, App<Element>] {
    let result: T | undefined;

    const app = createApp({
        setup() {
            result = composable();
            return () => null;
        }
    });

    for (const plugin of plugins) {
        app.use(plugin);
    }

    app.mount(document.createElement("div"));
    mounted.push(app);

    return [result as T, app];
}
