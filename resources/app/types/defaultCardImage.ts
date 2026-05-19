/** A single card-image focused defaultCard (front and optional back face). */
export type DefaultCardImage = {
    id: string;
    name: string;
    card_image_0: string | null;
    card_image_1: string | null;
    artist: string | null;
    cn: string;
    finishes: string[];
    set: { name: string; code: string; path: string | null };
    /**
     * Set only by the search-results endpoints, and only on rows where
     * the English oracle name didn't already match every query segment.
     * Carries the printed_name + lang of one translation that explains
     * the match, so the result grid can render a flag + foreign-language
     * name badge. Null/absent everywhere else.
     */
    matched_translation?: { lang: string; name: string } | null;
    /**
     * Foreign languages the card's oracle has any translation in.
     * English is implicit (never in the list). Drives the card-stack
     * language picker's narrowing once the user picks a card. Absent
     * outside the card-stack add/edit contexts.
     */
    available_langs?: string[];
};

/**
 * A printing returned by a deck printings endpoint — a DefaultCardImage
 * decorated with whether the user owns a copy outside their deckboxes and
 * whether it's the currently-selected printing for the target slot.
 */
export type DeckPrinting = DefaultCardImage & {
    in_collection: boolean;
    is_current: boolean;
};
