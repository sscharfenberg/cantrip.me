import type { DeckSort } from "Composables/useDeckSort.ts";
import type { DeckCardRow } from "Types/deckPage";

/** Supported deck card type groups, in display order. */
export type DeckCardGroup =
    "creature" | "planeswalker" | "battle" | "artifact" | "enchantment" | "instant" | "sorcery" | "land" | "other";

/**
 * Canonical display order and precedence for primary card types. A card is
 * placed in the first group its type line matches when scanned in this order.
 * Lands take precedence over creature / artifact / enchantment so that
 * "Creature Land" / "Artifact Land" cards land (no pun intended) in the land
 * bucket, matching what deck builders like Moxfield and Archidekt do.
 */
export const GROUP_ORDER: readonly DeckCardGroup[] = [
    "land",
    "creature",
    "planeswalker",
    "battle",
    "instant",
    "sorcery",
    "artifact",
    "enchantment",
    "other"
] as const;

/** Match tokens (case-insensitive) used to bucket a type line into a group. */
const GROUP_MATCHERS: Record<Exclude<DeckCardGroup, "other">, string> = {
    land: "Land",
    creature: "Creature",
    planeswalker: "Planeswalker",
    battle: "Battle",
    instant: "Instant",
    sorcery: "Sorcery",
    artifact: "Artifact",
    enchantment: "Enchantment"
};

/**
 * Determine the primary card-type group for a single card based on its
 * front-face type line. Falls back to `"other"` for cards with an empty
 * or unrecognised type line (tokens, schemes, etc.).
 */
export function resolveGroup(typeLine: string): DeckCardGroup {
    for (const group of GROUP_ORDER) {
        if (group === "other") continue;
        if (typeLine.includes(GROUP_MATCHERS[group])) return group;
    }
    return "other";
}

/**
 * Comparator for deck cards based on the active sort mode. Mana sorts by
 * `cmc` ascending and breaks ties alphabetically; name sorts purely by name.
 */
export function compareCards(mode: DeckSort): (a: DeckCardRow, b: DeckCardRow) => number {
    if (mode === "name") {
        return (a, b) => a.name.localeCompare(b.name);
    }
    return (a, b) => a.cmc - b.cmc || a.name.localeCompare(b.name);
}
