import { computed, ref } from "vue";

const STORAGE_KEY = "cantrip:add-cards-defaults";

/** Shape of the persisted defaults. All fields are optional — absent fields fall back to app defaults. */
export type AddCardsDefaults = {
    amount?: number;
    language?: string;
    condition?: string;
    finish?: string;
};

/** App-wide fallback values used when no user defaults are saved. */
const APP_DEFAULTS: Required<AddCardsDefaults> = {
    amount: 1,
    language: "en",
    condition: "",
    finish: "nonfoil"
};

/** Reactive state holding the currently saved defaults. Shared across all consumers. */
const savedDefaults = ref<AddCardsDefaults>(load());

/**
 * Read defaults from localStorage, returning an empty object on failure.
 *
 * The shape check after parsing matters as much as the try/catch: a stored
 * `"null"` or `"42"` is valid JSON, so it would sail past the catch and then
 * blow up in `hasSavedDefaults`, which calls `Object.keys` on it.
 */
function load(): AddCardsDefaults {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return {};
        const parsed: unknown = JSON.parse(raw);
        if (parsed === null || typeof parsed !== "object" || Array.isArray(parsed)) return {};
        return parsed as AddCardsDefaults;
    } catch {
        return {};
    }
}

/** Write the given defaults to localStorage and update reactive state. */
function persist(defaults: AddCardsDefaults): void {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(defaults));
    } catch {
        // Storage unavailable or over quota — the defaults still apply for the
        // rest of this session, they just won't survive a reload.
    }
    savedDefaults.value = { ...defaults };
}

/** Return type of the {@link useAddCardsDefaults} composable. */
export type UseAddCardsDefaultsReturn = ReturnType<typeof useAddCardsDefaults>;

/**
 * Composable for managing "add cards" page defaults via localStorage.
 *
 * Provides the resolved defaults (saved values merged over app defaults),
 * the raw saved defaults for display, and save/clear/hasSavedDefaults helpers.
 *
 * @example
 * ```ts
 * const { defaults, saveDefaults, clearDefaults } = useAddCardsDefaults();
 * const amount = ref(defaults.value.amount);
 * ```
 */
export function useAddCardsDefaults() {
    /** Merged defaults: saved values take precedence over app defaults. */
    const defaults = computed<Required<AddCardsDefaults>>(() => ({
        ...APP_DEFAULTS,
        ...savedDefaults.value
    }));

    /** True when the user has saved at least one custom default. */
    const hasSavedDefaults = computed(() => Object.keys(savedDefaults.value).length > 0);

    /** Reactive form values, initialized from saved defaults. */
    const amount = ref(defaults.value.amount);
    const language = ref(defaults.value.language);
    const condition = ref(defaults.value.condition);
    const finish = ref(defaults.value.finish);

    /** Incremented on reset to force keyed child components to remount. */
    const resetKey = ref(0);

    /** Persist the current form values as the user's defaults. */
    function saveDefaults(): void {
        persist({
            amount: amount.value,
            language: language.value,
            condition: condition.value,
            finish: finish.value
        });
    }

    /** Remove all saved defaults, reverting to app-wide fallbacks. */
    function clearDefaults(): void {
        localStorage.removeItem(STORAGE_KEY);
        savedDefaults.value = {};
    }

    /** Reset all form values back to defaults. Called after successful submission. */
    function resetToDefaults(): void {
        amount.value = defaults.value.amount;
        language.value = defaults.value.language;
        condition.value = defaults.value.condition;
        finish.value = defaults.value.finish;
        resetKey.value++;
    }

    return {
        defaults,
        savedDefaults,
        hasSavedDefaults,
        amount,
        language,
        condition,
        finish,
        resetKey,
        saveDefaults,
        clearDefaults,
        resetToDefaults
    };
}
