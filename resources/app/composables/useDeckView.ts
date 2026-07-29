import { usePage } from "@inertiajs/vue3";
import type { Ref } from "vue";
import { ref } from "vue";

/** Supported deck view modes. Mirrors `App\Enums\DeckView`. */
export type DeckView = "text" | "cards";

/** Return type of {@link useDeckView}. */
export type UseDeckViewReturn = {
    /** Current view mode for this deck — reactive, initialised from localStorage or user default. */
    viewMode: Ref<DeckView>;
    /** Persist a new view mode to localStorage and update the reactive state. */
    setViewMode: (mode: DeckView) => void;
};

/** Prefix for per-deck localStorage entries. */
const STORAGE_PREFIX = "cantrip:deck-view:";

/** Values that are considered valid persisted modes. */
const VALID_MODES: ReadonlySet<DeckView> = new Set<DeckView>(["text", "cards"]);

/**
 * Module-level cache: ensures every caller of `useDeckView(deckId)` for the
 * same deck id shares the same reactive ref. Without this, DeckPage.vue and
 * a nested DeckNavigationView would each have their own isolated state.
 */
const instances = new Map<string, Ref<DeckView>>();

/**
 * Read a persisted view override for the given deck id.
 *
 * Wrapped in try/catch to survive localStorage being unavailable
 * (SSR, privacy mode, disabled storage, quota errors on read).
 *
 * @param deckId - Deck identifier used as part of the storage key.
 * @returns The stored view mode, or null if none is set or storage is unavailable.
 */
function readOverride(deckId: string): DeckView | null {
    try {
        const raw = window.localStorage.getItem(`${STORAGE_PREFIX}${deckId}`);
        if (raw === null) return null;
        return VALID_MODES.has(raw as DeckView) ? (raw as DeckView) : null;
    } catch {
        return null;
    }
}

/**
 * Persist a view override for the given deck id. Fails silently on error.
 *
 * @param deckId - Deck identifier used as part of the storage key.
 * @param mode - View mode to persist.
 */
function writeOverride(deckId: string, mode: DeckView): void {
    try {
        window.localStorage.setItem(`${STORAGE_PREFIX}${deckId}`, mode);
    } catch {
        // Storage is unavailable or quota exceeded — view still works, just not persisted.
    }
}

/**
 * Remove every `cantrip:deck-view:*` entry from localStorage.
 *
 * Called from the dashboard when the user accepts "apply default to all
 * existing decks" after updating their preference. Iterates keys defensively
 * so a mid-loop mutation from `removeItem` can't skip entries, and stays
 * inside a single try/catch so any storage failure is a no-op.
 */
export function clearAllDeckViewOverrides(): void {
    try {
        const keysToRemove: string[] = [];
        for (let i = 0; i < window.localStorage.length; i++) {
            const key = window.localStorage.key(i);
            if (key !== null && key.startsWith(STORAGE_PREFIX)) {
                keysToRemove.push(key);
            }
        }
        for (const key of keysToRemove) {
            window.localStorage.removeItem(key);
        }
    } catch {
        // Nothing to do — if storage is unreachable there's nothing to clear.
    }
    // Drop any cached refs so the next `useDeckView(deckId)` call re-reads
    // from (the now-empty) localStorage and falls through to the user default.
    instances.clear();
}

/**
 * Per-deck view mode state, backed by localStorage with a user-default fallback.
 *
 * Flow on mount:
 *  1. Try reading the per-deck override from localStorage.
 *  2. Fall back to `auth.user.deck_view_default` from Inertia shared props.
 *  3. Final fallback is `"text"` if the user is unauthenticated (shouldn't
 *     happen on the deck page, but keeps the type safe).
 *
 * No SSR in this project (`INERTIA_SSR_ENABLED=false`), so `setup()` runs
 * client-side and the first paint already has the correct value — no flash.
 *
 * @param deckId - Identifier of the deck whose view state we're tracking.
 * @returns Reactive view mode and a setter that also persists to localStorage.
 */
export function useDeckView(deckId: string): UseDeckViewReturn {
    let viewMode = instances.get(deckId);
    if (viewMode === undefined) {
        const page = usePage();
        const userDefault = (page.props.auth.user?.deck_view_default ?? "text") as DeckView;
        viewMode = ref<DeckView>(readOverride(deckId) ?? userDefault);
        instances.set(deckId, viewMode);
    }

    /** Set a new view mode locally and persist it as a per-deck override. */
    function setViewMode(mode: DeckView): void {
        viewMode!.value = mode;
        writeOverride(deckId, mode);
    }

    return { viewMode, setViewMode };
}
