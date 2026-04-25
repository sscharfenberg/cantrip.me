/**
 * Minimal shape DeleteDeckModal needs: an id + name for the request and
 * heading, plus four flags so the modal can render a "what you're about to
 * lose" summary. Both the deck-list link and the deck detail page funnel
 * their own different data shapes (`DeckRow` and `DeckMeta` + companion)
 * through this interface so the modal stays caller-agnostic.
 */
export interface DeleteDeckTarget {
    id: string;
    name: string;
    cardCount: number;
    hasCompanion: boolean;
    hasDescription: boolean;
    hasImage: boolean;
}

/**
 * True when the deck has anything worth confirming before delete: at least
 * one card, a companion, a description, or a custom hero image. False means
 * the deck is effectively empty and callers can skip the confirm prompt and
 * fire the DELETE directly.
 */
export function hasDeletableContent(target: DeleteDeckTarget): boolean {
    return (
        target.cardCount > 0 || target.hasCompanion || target.hasDescription || target.hasImage
    );
}
