import { readonly, ref } from "vue";

/**
 * Tracks the oracle_card_id of the most-recently-added card so deck-card row
 * components can briefly highlight the matching `<li>`.
 *
 * Module-level state (not factory-scoped) so the quick-add component and the
 * deck card list see the same signal without prop drilling.
 */
const recentlyAddedOracleId = ref<string | null>(null);
let clearTimer: ReturnType<typeof setTimeout> | null = null;

/** How long the highlight class stays on the deck-card row, in ms. */
const HIGHLIGHT_DURATION = 1500;

/** Mark an oracle card as just-added; auto-clears after {@link HIGHLIGHT_DURATION}. */
export function markRecentlyAdded(oracleCardId: string): void {
    recentlyAddedOracleId.value = oracleCardId;
    if (clearTimer) clearTimeout(clearTimer);
    clearTimer = setTimeout(() => {
        recentlyAddedOracleId.value = null;
        clearTimer = null;
    }, HIGHLIGHT_DURATION);
}

/** Read-only ref of the currently-highlighted oracle id (or null). */
export function useRecentlyAddedId() {
    return readonly(recentlyAddedOracleId);
}
