import type { DefaultCardImage } from "Types/defaultCardImage.ts";

/** Default card image attached to a commander. */
export interface DeckCommanderDefaultCard {
    id: string;
    card_image_0: string | null;
    card_image_1: string | null;
}

/** A commander in the deck's command zone. */
export interface DeckCommander {
    oracle_card_id: string;
    name: string;
    color_identity: string | null;
    cmc: number;
    mana_cost: (string | null)[];
    is_partner: boolean;
    default_card: DeckCommanderDefaultCard;
}

/** Default card (specific printing) attached to a deck card. */
export interface DeckCardDefaultCard {
    id: string | null;
    name: string | null;
    card_image_0: string | null;
    card_image_1: string | null;
    set: { name: string; code: string } | null;
}

/** A single card entry in the deck. */
export interface DeckCardRow {
    id: string;
    oracle_card_id: string;
    name: string;
    color_identity: string | null;
    cmc: number;
    type_line: string;
    mana_cost: (string | null)[];
    is_basic_land: boolean;
    is_unlimited: boolean;
    zone: string;
    quantity: number;
    finish: string;
    language: string;
    category_id: string | null;
    card_stack_id: string | null;
    default_card: DeckCardDefaultCard;
}

/** A user-defined category within a deck. */
export interface DeckCategoryRow {
    id: string;
    name: string;
}

/**
 * One result row returned by the deck card search API.
 *
 * Same shape for both the oracle endpoint (`/card-search/oracle`) and the
 * printings endpoint (`/card-search/printings`). `printing` is nullable on
 * the oracle path in the rare case an oracle card has no default card
 * printing yet; the printings path always populates it.
 */
export interface DeckSearchResult {
    oracle_id: string;
    name: string;
    cmc: number;
    color_identity: string | null;
    printing: DefaultCardImage | null;
}

/** Deck metadata as passed by the controller. */
export interface DeckMeta {
    id: string;
    name: string;
    description: string | null;
    format: string;
    state: string;
    visibility: string;
    colors: string | null;
    bracket: number | null;
    card_count: number;
    max_deck_size: number | null;
    max_sideboard_size: number;
    max_copies: number;
    is_singleton: boolean;
    last_activity: string;
    default_card_image: {
        card_image_0: string | null;
        card_image_1: string | null;
    } | null;
}