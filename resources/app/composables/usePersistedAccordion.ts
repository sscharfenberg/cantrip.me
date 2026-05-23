/**
 * Persist an `<Accordion>` open/closed state in `localStorage` so a
 * user's choice survives navigation and full page reloads. Returns
 * `initialOpen` for the prop and an `onToggle` handler to wire to the
 * `@toggle` event.
 *
 * Reads the stored value at composable invocation (during component
 * setup) so the accordion mounts in the user's last-known state with
 * no flicker. Defaults to `defaultOpen` when nothing is stored yet
 * (first visit, cleared storage, private browsing).
 *
 * The localStorage access is guarded so a hostile / disabled storage
 * (Safari private mode quotas, iframe sandbox) degrades to the
 * default state instead of throwing.
 */
export interface UsePersistedAccordionReturn {
    initialOpen: boolean;
    onToggle: (isOpen: boolean) => void;
}

export const usePersistedAccordion = (
    storageKey: string,
    defaultOpen = true
): UsePersistedAccordionReturn => {
    const stored = safeGetItem(storageKey);
    const initialOpen = stored === null ? defaultOpen : stored === "true";

    const onToggle = (isOpen: boolean): void => {
        safeSetItem(storageKey, String(isOpen));
    };

    return { initialOpen, onToggle };
};

const safeGetItem = (key: string): string | null => {
    try {
        return typeof localStorage === "undefined" ? null : localStorage.getItem(key);
    } catch {
        return null;
    }
};

const safeSetItem = (key: string, value: string): void => {
    try {
        if (typeof localStorage !== "undefined") localStorage.setItem(key, value);
    } catch {
        /* swallow — degrade silently when storage is unavailable */
    }
};
