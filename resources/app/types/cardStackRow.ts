/**
 * One deck claiming a card stack via the `deck_card_card_stack` pivot.
 * Surfaced collection-side as the "Reserved for [deck]" badge in
 * Phase 2.5. The schema permits N decks per stack, hence the array;
 * within a single deck multi-claim collapses (the service deduplicates
 * per `(stack_id, deck_id)`).
 */
export interface StackClaim {
    deck_id: string;
    deck_name: string;
}

/** Row shape for card stacks in the DataTable, as returned by ContainerController::show. */
export interface CardStackRow {
    id: string;
    name: string;
    set_name: string;
    set_code: string;
    set_path: string | null;
    collector_number: string;
    amount: number;
    condition: string | null;
    finish: string | null;
    language: string;
    art_crop: string | null;
    /** Front face card image URL. */
    card_image_0: string | null;
    /** Unit price of one card in the user's selected currency. */
    price: number;
    /** Total price of the stack (unit price × amount). */
    total_price: number;
    /** ISO 8601 timestamp when the card stack was created. */
    created_at: string;
    /** ISO 8601 timestamp when the card stack was last updated. */
    updated_at: string;
    /**
     * Decks that have claimed this stack via a deck_card_card_stack
     * pivot row. Empty array when nothing claims it. Drives the
     * "Reserved for [deck]" badge in the collection / container views.
     */
    claims: StackClaim[];
}
