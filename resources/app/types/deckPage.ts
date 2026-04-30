import type { DefaultCardArtCrop } from "Types/defaultCardArtCrop.ts";
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

/** A Magic "Companion" keyword card attached to the deck. */
export interface DeckCompanion {
    oracle_card_id: string;
    name: string;
    color_identity: string | null;
    cmc: number;
    mana_cost: (string | null)[];
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
    /** True when the card violates a per-card format rule (pool legality, copy limit, color identity). */
    is_illegal: boolean;
    /** True when the card is on Wizards' Game Changer list. Display gated by `DeckMeta.uses_game_changer_list`. */
    is_game_changer: boolean;
    zone: string;
    quantity: number;
    finish: string;
    language: string;
    category_id: string | null;
    /**
     * Per-card collection status, computed only for owners in mode C
     * (the deck has at least one claimed stack). Null in modes A and B
     * and for non-owners. Mode B's count-based display is Phase 2.2 work.
     * One of: `claimed_for_this_deck` | `available` |
     * `claimed_by_other_deck` | `wrong_printing` | `not_owned`.
     */
    collection_status: string | null;
    default_card: DeckCardDefaultCard;
}

/** A user-defined category within a deck. */
export interface DeckCategoryRow {
    id: string;
    name: string;
}

/**
 * i18n suffix identifying which per-card companion rule was broken. Yorion
 * is excluded — its violation type is `companion_size_restriction` and
 * always maps to the `yorion` message.
 */
export type PerCardCompanionKey =
    | "gyruda"
    | "jegantha"
    | "kaheera"
    | "keruga"
    | "lurrus"
    | "lutri"
    | "obosh"
    | "umori"
    | "zirda";

/**
 * A single legality violation on the deck.
 *
 * Per-card violations (`pool_legality`, `copy_limit`, `color_identity`,
 * `companion_restriction`) carry the offending deck_card IDs. Deck-level
 * violations (`deck_size_min`, `deck_size_max`, `sideboard_size_max`,
 * `companion_size_restriction` for Yorion) carry the comparison numbers.
 */
export type DeckViolation =
    | { type: "pool_legality"; card_ids: string[] }
    | { type: "copy_limit"; card_ids: string[] }
    | { type: "color_identity"; card_ids: string[] }
    | { type: "deck_size_min"; current: number; min: number }
    | { type: "deck_size_max"; current: number; max: number }
    | { type: "sideboard_size_max"; current: number; max: number }
    | { type: "companion_restriction"; message_key: PerCardCompanionKey; card_ids: string[] }
    | { type: "companion_size_restriction"; message_key: "yorion"; current: number; min: number };

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
    /**
     * True when adding this card would break the deck's current companion
     * restriction. Soft signal — the add is not blocked, the frontend just
     * renders a warning badge. Always `false` (or absent) when the deck has
     * no companion, and always `false` for Lutri/Umori/Yorion profiles for
     * now (they need deck-state and haven't been wired through).
     */
    violates_companion?: boolean;
    printing: DefaultCardImage | null;
}

/** One face of a quick-add result — same shape as commander faces. */
export interface QuickAddCardFace {
    type_line: string | null;
    mana_cost: string | null;
}

/**
 * One result row from the quick-add oracle endpoint
 * (`/api/decks/{deck}/oracle-cards`).
 *
 * Oracle-level (one row per oracle card, no printings). `faces` carries the
 * per-face `type_line` + `mana_cost` so the UI can render multi-faced cards.
 * `default_card_id` is the newest printing's UUID, used for the add-card POST.
 * `is_basic_land` and `has_unlimited_copies` flag cards exempt from the format's
 * per-card copy limit ("a deck can have any number of cards named X" rule), so
 * the result row should stay in the popover after add instead of being removed.
 */
export interface QuickAddCardResult {
    id: string;
    name: string;
    color_identity: string | null;
    default_card_id: string | null;
    is_basic_land: boolean;
    has_unlimited_copies: boolean;
    faces: QuickAddCardFace[];
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
    enforces_color_identity: boolean;
    allows_companion: boolean;
    /** Oracle names of companions banned in this format (e.g. Lutri in Commander). */
    banned_as_companion: string[];
    /** Whether the format uses the Game Changer list — gates the per-card GC badge in the UI. */
    uses_game_changer_list: boolean;
    last_activity: string;
    /** Deck hero / banner art — the chosen printing's art crop, or null. */
    hero_card: DefaultCardArtCrop | null;
}