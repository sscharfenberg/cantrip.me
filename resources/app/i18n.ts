import { nextTick } from "vue";
import { createI18n } from "vue-i18n";

import type { I18n, I18nOptions, Locale, Composer } from "vue-i18n";

/** Module-level reference kept so callers can retrieve the instance without prop-drilling. */
let i18nInstance: I18n | null = null;

/**
 * Return the module-level i18n instance.
 *
 * Throws if called before {@link setupI18n} has been invoked (i.e. before the
 * app is mounted).
 */
export function getI18n(): I18n {
    if (!i18nInstance) {
        throw new Error("i18n has not been initialized. Call setupI18n first.");
    }
    return i18nInstance;
}

/**
 * Write the active locale on an i18n instance.
 *
 * The app always uses composition mode (`legacy: false`), so the locale lives
 * on `i18n.global.locale` as a `Ref<string>`.
 *
 * @param i18n   - The i18n instance to update.
 * @param locale - The locale to activate (e.g. `"de"` or `"en"`).
 */
export function setLocale(i18n: I18n, locale: Locale): void {
    (i18n.global as unknown as Composer).locale.value = locale;
}

/**
 * Create and configure the global i18n instance.
 *
 * Stores the instance at module level so it can be retrieved later via
 * {@link getI18n}. Called once during app bootstrap in `main.ts`.
 *
 * @param options - vue-i18n creation options; defaults to English locale.
 * @return The configured i18n instance.
 */
export function setupI18n(options: I18nOptions = { locale: "en" }): I18n {
    const i18n = createI18n(options);
    i18nInstance = i18n;
    setI18nLanguage(i18n, options.locale!);
    return i18n;
}

/**
 * Activate a locale on the i18n instance and sync the `<html lang>` attribute.
 *
 * @param i18n   - The i18n instance to update.
 * @param locale - The locale to activate (e.g. `"de"` or `"en"`).
 */
export function setI18nLanguage(i18n: I18n, locale: Locale): void {
    setLocale(i18n, locale);
    document.querySelector("html")!.setAttribute("lang", locale);
}

const getResourceMessages = (r: any) => r.default || r;

/**
 * Dynamically import a locale's message file and register it on the i18n instance.
 *
 * Messages are loaded on demand so only the active locale's JSON is fetched at
 * startup. Returns a `nextTick` promise so callers can await DOM stabilization
 * before mounting the app.
 *
 * @param i18n   - The i18n instance to register messages on.
 * @param locale - The locale to load (e.g. `"de"` or `"en"`).
 */
export async function loadLocaleMessages(i18n: I18n, locale: Locale) {
    const messages = await import(`./lang/${locale}.json`).then(getResourceMessages);

    i18n.global.setLocaleMessage(locale, messages);

    return nextTick();
}
