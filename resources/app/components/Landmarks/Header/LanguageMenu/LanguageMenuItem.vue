<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { getI18n, loadLocaleMessages, setI18nLanguage } from "@/i18n.ts";
const props = defineProps<{
    /** BCP-47 locale code (e.g. `"de"`, `"en"`) this menu item represents. */
    locale: string;
}>();
/** The currently active locale from vue-i18n, used to highlight the selected item. */
const { locale: currentLocale } = useI18n();
/** @emits close — Fired to dismiss the language popover after a locale switch. */
const emit = defineEmits(["close"]);
/**
 * Switches the application locale when the user picks a different language.
 * 1. Lazy-loads the translation messages for the target locale.
 * 2. Sets the active i18n locale (updates all `$t()` calls reactively).
 * 3. Persists the choice on the server so it survives page reloads.
 * No-ops if the selected locale is already active.
 */
const onLocaleChange = async () => {
    if (currentLocale.value === props.locale) return;

    emit("close");
    const i18n = getI18n();

    await loadLocaleMessages(i18n, props.locale);
    setI18nLanguage(i18n, props.locale);

    await fetch("/lang/" + props.locale, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": usePage().props.csrfToken as string,
            Accept: "application/json"
        }
    });
};
/** Resolve a locale code to the bundled flag SVG URL (Vite-rewritten at build). */
const flagSrc = (lang: string): string => new URL(`../../../../assets/flags/${lang}.svg`, import.meta.url).href;
/**
 * Endonym label for a locale — always rendered in the target language (not
 * the current UI language) so users recognise their native-tongue entry in
 * the language menu regardless of the app's current locale.
 */
const localeLabel = (lang: string): string =>
    [
        { locale: "en", label: "English language" },
        { locale: "de", label: "Deutsche Sprache" }
    ].find(l => l.locale === lang)?.label ?? lang;
</script>

<template>
    <li>
        <button
            type="button"
            class="popover-list-item"
            :class="{ 'popover-list-item--selected': currentLocale === locale }"
            @click="onLocaleChange"
        >
            <img class="flag small" :src="flagSrc(locale)" :alt="localeLabel(locale)" />
            {{ locale }}
        </button>
    </li>
</template>
