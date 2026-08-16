import { createI18n } from "vue-i18n";
import type { I18n } from "vue-i18n";

/** Locale message tree, shaped like the JSON files in `resources/app/lang/`. */
export type TestMessages = Record<string, unknown>;

/**
 * Build a vue-i18n instance for tests.
 *
 * Defaults to **key echo**: with no messages registered, vue-i18n falls back to
 * returning the lookup key itself, so `$t("pages.login.title")` renders as the
 * literal string `pages.login.title`. Specs therefore assert on the key, and
 * rewording `lang/de.json` can never turn an unrelated test red. Missing-key
 * warnings are silenced for the same reason — under key echo every key is
 * "missing" by design.
 *
 * Pass `messages` when the test is *about* translated output — pluralisation,
 * interpolation, or a component that slices the rendered string.
 *
 * @param messages - Per-locale message trees to register (e.g. `{ de: {...} }`).
 * @return A composition-mode i18n instance ready to install as a Vue plugin.
 */
export function createTestI18n(messages: Record<string, TestMessages> = {}): I18n {
    return createI18n({
        legacy: false,
        locale: "de",
        fallbackLocale: "en",
        messages: { de: {}, en: {}, ...messages },
        missingWarn: false,
        fallbackWarn: false
    });
}
