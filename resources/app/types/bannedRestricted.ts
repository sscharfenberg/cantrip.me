/******************************************************************************
 * Payload of the banned & restricted page (`/b+r`), built by
 * `App\Services\BannedRestrictedService::forPage()`.
 *****************************************************************************/

/** Colour group a card is listed under — WUBRG, then multicolor, colorless and lands. */
export type BannedRestrictedGroupKey = "W" | "U" | "B" | "R" | "G" | "multicolor" | "colorless" | "land";

/** One colour group of a list: the oracle ids of its cards, sorted by name. */
export type BannedRestrictedGroup = {
    group: BannedRestrictedGroupKey;
    cards: string[];
};

/** A blanket ban Wizards' lists name as one line rather than card by card. */
export type BannedRestrictedCategoryKey = "conspiracy" | "ante" | "offensive" | "sticker";

/** One blanket ban: the oracle ids of its cards, sorted by name. */
export type BannedRestrictedCategory = {
    category: BannedRestrictedCategoryKey;
    cards: string[];
};

/** One list — a format's banned or its restricted cards. Both parts arrive in display order. */
export type BannedRestrictedCards = {
    /** The cards banned by name, by colour. */
    groups: BannedRestrictedGroup[];
    /** The blanket bans. */
    categories: BannedRestrictedCategory[];
};

/** One format's tab. Either list may be empty. */
export type BannedRestrictedFormat = {
    /** `CardFormat` value, also the tab's URL hash (`#legacy`). */
    format: string;
    /** Decks in this format on cantrip.me, across every user — orders the tabs. */
    decks: number;
    banned: BannedRestrictedCards;
    restricted: BannedRestrictedCards;
};

/** A card on at least one list, shipped once however many lists name it. */
export type BannedRestrictedCard = {
    name: string;
    /** Per-face mana costs, front first. */
    mana_cost: (string | null)[];
    /** The newest printing — pictures the card and opens its preview. Null when it has none. */
    printing: { id: string; image: string | null } | null;
};
