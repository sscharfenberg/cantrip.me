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
