import { createI18n } from "vue-i18n";
import type { Composer, I18n } from "vue-i18n";

/** Locale message tree, shaped like the JSON files in `resources/app/lang/`. */
export type TestMessages = Record<string, unknown>;

/** The instance `setup.ts` installed for the current test; see {@link setTestMessages}. */
let current: I18n | null = null;

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
 * `setup.ts` calls this once per test and installs the result as a Vue Test
 * Utils global plugin. A spec that needs real messages should reach for
 * {@link setTestMessages} rather than building a second instance — see the note
 * there.
 *
 * @param messages - Per-locale message trees to register (e.g. `{ de: {...} }`).
 * @return A composition-mode i18n instance ready to install as a Vue plugin.
 */
export function createTestI18n(messages: Record<string, TestMessages> = {}): I18n {
    // Assigned straight into `current` so the call picks up `I18n` as its
    // contextual type; bound to a bare `const` first, `createI18n` infers a
    // narrower generic from the message tree that no longer fits.
    current = createI18n({
        legacy: false,
        locale: "de",
        fallbackLocale: "en",
        messages: { de: {}, en: {}, ...messages },
        missingWarn: false,
        fallbackWarn: false
    });

    return current;
}

/**
 * Register real messages on the i18n instance the current test already has.
 *
 * Use this — not a second `createTestI18n()` passed through
 * `global: { plugins: [...] }`. Vue Test Utils *appends* a mount's plugins to
 * `config.global.plugins`, so passing another instance installs vue-i18n twice
 * on the same app: it re-registers `i18n-t` and friends and logs seven
 * "already been registered" warnings per mount.
 *
 * Anything not registered here keeps echoing its key, so a spec can pin the one
 * message it cares about and leave the rest alone.
 *
 * ```ts
 * setTestMessages({ de: { pages: { login: { title: "Anmelden" } } } });
 * expect(mount(Login).text()).toContain("Anmelden");
 * ```
 *
 * @param messages - Per-locale message trees to merge in.
 */
export function setTestMessages(messages: Record<string, TestMessages>): void {
    if (current === null) {
        throw new Error("setTestMessages: no i18n instance for this test — setup.ts creates one in beforeEach.");
    }

    for (const [locale, tree] of Object.entries(messages)) {
        current.global.setLocaleMessage(locale, tree as never);
    }
}

/** Switch the locale of the current test's i18n instance. */
export function setTestLocale(locale: string): void {
    if (current === null) {
        throw new Error("setTestLocale: no i18n instance for this test — setup.ts creates one in beforeEach.");
    }

    (current.global as unknown as Composer).locale.value = locale;
}
