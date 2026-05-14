import type { StackClaim } from "Types/cardStackRow";

/** A single legality entry for a card format. */
export interface CardLegality {
    format: string;
    legality: string;
}

/** A single ruling for a card. */
export interface CardRuling {
    source: string;
    published_at: string | null;
    comment: string;
}

/** One stack of the previewed printing in the viewer's own collection. */
export interface CollectionSamePrintingEntry {
    /** Container name or null when the stack is unsorted. */
    container_name: string | null;
    amount: number;
}

/** One stack of a *different* printing of the same oracle card in the viewer's collection. */
export interface CollectionOtherPrintingEntry {
    default_card_id: string;
    set_code: string | null;
    set_name: string | null;
    set_path: string | null;
    collector_number: string;
    /** Front-face image URL; rendered as a tiny thumbnail in the modal. */
    card_image_0: string | null;
    /** Container name or null when the stack is unsorted. */
    container_name: string | null;
    amount: number;
}

/** Viewer-collection block — present only for authenticated requests. */
export interface CollectionInfo {
    same_printing: CollectionSamePrintingEntry[];
    other_printings: CollectionOtherPrintingEntry[];
}

/**
 * Response shape from the card preview endpoints.
 *
 * Card-level fields (name, images, set, artist, collector_number, price,
 * scryfall_uri, legalities) are returned by both the stack endpoint and
 * the deck-card endpoint. Stack-only fields (`amount`, `condition`,
 * `finish`, `language`, `created_at`, `updated_at`, `total_price`,
 * `claims`) are present only on the stack endpoint and gated by `v-if`
 * in the modal template.
 */
export interface CardPreview {
    name: string;
    card_image_0: string | null;
    card_image_1: string | null;
    set_code: string | null;
    set_name: string | null;
    set_path: string | null;
    collector_number: string;
    artist: string | null;
    price: number;
    scryfall_uri: string | null;
    /**
     * Mana this card can produce (single-letter codes: W/U/B/R/G/C),
     * aggregated across all faces by Scryfall. Null when the card
     * produces no mana.
     */
    produced_mana: string[] | null;
    /** True when the card is on Wizards' Commander Game Changer list. Format-agnostic — surfaced in the preview modal regardless of caller context. */
    is_game_changer: boolean;
    /** True when the card is tagged as mass land denial (Scryfall `otag:mass-land-denial`). Format-agnostic. */
    is_mld: boolean;
    legalities: CardLegality[];
    /** Rulings for this card, sorted by published_at descending (newest first). Empty when none. */
    rulings: CardRuling[];
    amount?: number;
    condition?: string | null;
    finish?: string | null;
    language?: string;
    created_at?: string;
    updated_at?: string;
    total_price?: number;
    /** True when the underlying stack is flagged as a proxy. Stack-only. */
    proxy?: boolean;
    /**
     * Decks that have claimed this stack via a `deck_card_card_stack`
     * pivot row. Surfaced as the "Reserved for [deck]" badge in the
     * preview modal body. Stack-only.
     */
    claims?: StackClaim[];
    /**
     * Viewer-collection info — shipped only when the request is
     * authenticated. Always reflects the *viewer's* collection, not
     * the stack owner's, so a viewer browsing someone else's public
     * stack still sees what they personally own.
     */
    collection?: CollectionInfo;
}
