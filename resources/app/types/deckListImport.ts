import type { CommanderResult } from "Components/Deck/ShowCommanderOverview.vue";
import type { CollectionAvailability } from "Types/deckPage.ts";
import type { FormatCapabilities } from "Types/formatCapabilities.ts";

/**
 * Shapes of the deck list import's parse response — produced by
 * `App\Services\DeckList\DeckListResolver`.
 */

/** Zones a reviewed line can land in. The command zone travels separately. */
export type DeckListZone = "main" | "side" | "companion";

/** One card as the review page shows it, with the facts its warnings are derived from. */
export interface DeckListCard {
    oracle_card_id: string;
    name: string;
    color_identity: string | null;
    /** Front face type line. */
    type_line: string | null;
    /** Legal (or restricted) in the deck's format and in its pool. */
    is_legal: boolean;
    /** Most copies the format allows; null for basic lands and "any number" cards. */
    copy_limit: number | null;
    /** The printing that will be imported — pinned by the paste, or Quick Add's preference. */
    default_card_id: string;
    set_code: string;
    set_name: string | null;
    /** Set icon path. */
    set_path: string | null;
    collector_number: string;
    /** Front-face image of the printing, for the thumbnail. */
    image: string | null;
}

/** A card offered for a line — a suggestion, a search result, or the line's own card. */
export interface DeckListCandidate {
    card: DeckListCard;
    /** Planned-deck availability; null while the collection master switch is off. */
    availability: CollectionAvailability | null;
}

/** One pasted line after resolution. */
export interface DeckListLine {
    /** 1-based line number in the paste — also the line's identity on the review page. */
    line: number;
    raw: string;
    section: number;
    zone: DeckListZone;
    /** The parser guessed the zone (MTGO-style blank-line sideboard). */
    zone_guessed: boolean;
    quantity: number;
    name: string | null;
    category: string | null;
    status: "resolved" | "unresolved";
    reason: "unparseable" | "not_found" | "ambiguous" | null;
    /** Server-side remarks: the pinned printing was not found, or a command-zone card was ineligible. */
    notices: Array<"printing_not_found" | "not_commander">;
    card: DeckListCard | null;
    availability: CollectionAvailability | null;
}

/** A header's block (or, without headers, a blank-line block). */
export interface DeckListSection {
    index: number;
    label: string | null;
    zone: DeckListZone | "command";
    zone_guessed: boolean;
}

/** The command zone, pre-filled from the paste where it could be. */
export interface DeckListCommandZone {
    commander: CommanderResult | null;
    /** Partner-type card — sent as `companion_id`, like the create form. */
    partner: CommanderResult | null;
    signature_spell: CommanderResult | null;
    /** Oracle id → printing for the pre-filled cards — the paste's printing, or the collection's. */
    printings: Record<string, string>;
}

/** The parse endpoint's response. */
export interface DeckListParseResult {
    sections: DeckListSection[];
    lines: DeckListLine[];
    /** Maybeboard lines skipped by the parser. */
    dropped: number;
    command_zone: DeckListCommandZone;
    rules: FormatCapabilities & { allowsCompanion: boolean };
    /** Whether the collection master switch is on (availability shown). */
    collection: boolean;
}
