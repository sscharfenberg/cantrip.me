/******************************************************************************
 * Main app entrypoint
 *****************************************************************************/
import "@/styles/app.scss";
import { createInertiaApp, router } from "@inertiajs/vue3";
import type { ProgressBarOptions } from "@sscharfenberg/progressbar";
import { doesProgressBarExist, finishProgress, setProgress, startProgress } from "@sscharfenberg/progressbar";
import FloatingVue from "floating-vue";
import type { DefineComponent } from "vue";
import { createApp, h } from "vue";
import type { Composer } from "vue-i18n";
import { useBreadcrumbs } from "@/composables/useBreadcrumbs.ts";
import { useNavigation } from "@/composables/useNavigation.ts";
import { useToast } from "@/composables/useToast.ts";
import { setupI18n, loadLocaleMessages, getI18n } from "@/i18n.ts";
import FullLayout from "./components/Layout/FullLayout.vue";
const progressBarSettings: ProgressBarOptions = { ariaLabel: "Ladefortschritt", parent: "#app" };
let timeout: ReturnType<typeof setTimeout>;

/******************************************************************************
 * mount Inertia App
 *****************************************************************************/
createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob<DefineComponent>("./pages/**/*.vue");
        const pageLoader = pages[`./pages/${name}.vue`];
        if (!pageLoader) {
            throw new Error(`Page not found: ${name}`);
        }

        return pageLoader();
    },
    layout: () => FullLayout,
    setup({ el, App, props, plugin }) {
        const { locale, supportedLocales } = props.initialPage.props as {
            locale?: string;
            supportedLocales?: string[];
        };
        const initialLocale = locale || "de";
        const availableLocales = supportedLocales || ["en"];

        const i18n = setupI18n({
            legacy: false,
            locale: initialLocale,
            fallbackLocale: availableLocales.filter(locale => locale !== initialLocale)[0],
            messages: {}
        });

        const app = createApp({ render: () => h(App, props) });

        app.use(plugin);
        app.use(i18n);
        app.use(FloatingVue, {
            themes: {
                tooltip: {
                    triggers: ["hover", "focus", "click"],
                    hideTriggers: ["hover", "focus", "click"],
                    html: true
                }
            }
        });

        loadLocaleMessages(i18n, initialLocale).then(() => app.mount(el));
    },
    title: title => (title ? `cantrip.me: ${title}` : `cantrip.me`),
    progress: false // disable inertia NProgress implementation for more control
}).then(() => {
    console.log("app created");
});

/******************************************************************************
 * Inertia router
 *****************************************************************************/
/**
 * True for Inertia partial reloads (`router.reload({ only: [...] })`).
 * Partial reloads refresh a subset of page props in place — they aren't
 * navigations — so we skip the global `navigating` flag and the progress
 * bar for them. Otherwise the breadcrumb (`v-show="!navigating"`) would
 * hide and unhide on every in-place refresh, causing a visible page jerk.
 */
const isPartialReload = (event: { detail: { visit: { only: string[] } } }): boolean =>
    event.detail.visit.only.length > 0;

router.on("start", event => {
    if (!event.detail.visit.preserveState) {
        useBreadcrumbs().setBreadcrumbs([]);
    }
    if (isPartialReload(event)) return;
    useNavigation().navigating.value = true;
    timeout = setTimeout(() => startProgress(progressBarSettings), 250);
});
router.on("progress", event => {
    if (doesProgressBarExist() && event.detail.progress?.percentage) {
        setProgress((event.detail.progress.percentage / 100) * 0.9);
    }
});
router.on("finish", event => {
    if (isPartialReload(event)) return;
    useNavigation().navigating.value = false;
    clearTimeout(timeout);
    if (doesProgressBarExist() && event.detail.visit.completed) {
        finishProgress();
    } else if (event.detail.visit.interrupted) {
        setProgress(0);
    } else if (event.detail.visit.cancelled) {
        finishProgress();
    }
});

/**
 * Intercept maintenance-mode (503) responses globally. When `php artisan down`
 * is active, Laravel returns a 503 HTML page that Inertia treats as a
 * non-Inertia response — by default it shows a modal with the raw HTML.
 * Replace that with a friendly toast so users with the SPA already loaded
 * get a clear hint instead of a silent click. Returning `false` cancels
 * Inertia's default modal. Throttled so a user clicking several links during
 * a deploy doesn't queue up a wall of toasts.
 */
let lastMaintenanceToastAt = 0;
router.on("httpException", event => {
    if (event.detail.response.status !== 503) return;
    const now = Date.now();
    if (now - lastMaintenanceToastAt >= 6000) {
        lastMaintenanceToastAt = now;
        const t = (getI18n().global as unknown as Composer).t;
        useToast().addToast(t("toast.maintenance"), "warning", 6000);
    }
    return false;
});
