import type { ManaSource } from "Types/deckPage.ts";

/**
 * How much each card counts as a colored mana source, per the "How to
 * account for mana producers" and "Treasure generators" sections of
 * Frank Karsten's 2022 update (the article `karstenArticleUrl` links
 * to). The recommendation tables in `frankKarstenAnalysis.ts` assume
 * lands are the only sources; these rules are his own adjustments for
 * everything else:
 *
 *   - Lands, fetchlands included, are a full source of every color they
 *     make or can fetch, for every spell.
 *   - Mana dorks (Llanowar Elves, Birds of Paradise) are half a source,
 *     for spells of mana value two or more — "Bolt the Bird".
 *   - Mana rocks (Arcane Signet, Signets) are three-fourths of a source,
 *     for spells of mana value three or more.
 *   - Two-mana land ramp (Farseek, Three Visits, Rampant Growth,
 *     Sakura-Tribe Elder, The Birth of Meletis) is three-fourths of a
 *     source of every color it could find, for spells of mana value
 *     three or more.
 *   - Three-mana land ramp (Cultivate) counts "only ... for cards with
 *     mana value four or higher, and I wouldn't count them as
 *     effectively because you may have better things to cast on turn
 *     three ... for cards with mana value five or higher, I may count
 *     each of these ramp effects as half of a source". The article
 *     names no figure for mana value four; this uses a quarter there.
 *   - A Treasure is a quarter of a source of any color, for spells of
 *     mana value three or more (for a two-mana maker).
 *   - Rituals are not sources: a one-shot burst fixes no later turn.
 *
 * Karsten states each threshold for the cards he names. They generalise
 * here by the producer's own mana value — it helps with spells cast the
 * turn after it resolves, so the gate is one above it (a three-mana rock
 * counts from four), and ramp of three mana or more follows the
 * Cultivate rule at its own cost plus one and plus two.
 *
 * Simplified, not modelled: Fabled Passage–style basic-only fetches stay
 * full sources (Karsten drops them to ⅔ in three-colour decks with heavy
 * requirements), spell/land MDFCs stay full (he uses 0.8 for non-mythic
 * ones), a Treasure maker counts one Treasure however many it makes,
 * the gate reads the card's mana value and so ignores activation costs
 * (Wayfarer's Bauble) and adventure halves, and cantrips do not count.
 */

/** Fragile mana creature: half a source. */
const DORK_WEIGHT = 1 / 2;

/** Mana rock — sturdier than a dork, still a spell: three-fourths. */
const ROCK_WEIGHT = 3 / 4;

/** One Treasure (or Gold) token: a quarter. */
const TOKEN_WEIGHT = 1 / 4;

/** Land ramp castable on turn two: three-fourths. */
const CHEAP_RAMP_WEIGHT = 3 / 4;

/** Land ramp of three mana or more, the turn after it resolves: a quarter. */
const RAMP_EARLY_WEIGHT = 1 / 4;

/** Land ramp of three mana or more, from two turns after it resolves: half. */
const RAMP_WEIGHT = 1 / 2;

/** Highest mana value that still counts as Karsten's "two-mana ramp". */
const CHEAP_RAMP_MAX_MV = 2;

/**
 * Card shape the classifier reads. `DeckCardRow`, `DeckCommander` and
 * `DeckCompanion` all satisfy it; the pattern and MDFC fields are
 * optional because only deck rows carry them.
 */
export interface SourceCard {
    type_line: string;
    cmc: number;
    produced_mana: string[] | null;
    mana_source: ManaSource | null;
    fetch_pattern?: string | null;
    land_search_pattern?: string | null;
    is_mdfc_land?: boolean;
}

/**
 * One way a card supplies colored mana: the colors, how much of a
 * source one copy is worth, and the cheapest spell it counts toward.
 */
export interface SourceRole {
    /** Produced or findable colors. May include `C`; callers match by color. */
    colors: string[];
    /** Fraction of a source per copy. */
    weight: number;
    /** Lowest mana value of a spell this role counts toward; 0 means every spell. */
    minMv: number;
}

/**
 * The role a non-land's own `produced_mana` plays, by where that mana
 * comes from (`mana_source`, derived from the card's front face).
 */
function producerRole(card: SourceCard): SourceRole | null {
    if (!card.produced_mana?.length) return null;
    const minMv = card.cmc + 1;
    if (card.mana_source === "token") return { colors: card.produced_mana, weight: TOKEN_WEIGHT, minMv };
    if (card.mana_source !== "ability" || /Instant|Sorcery/.test(card.type_line)) return null;
    const weight = card.type_line.includes("Creature") ? DORK_WEIGHT : ROCK_WEIGHT;
    return { colors: card.produced_mana, weight, minMv };
}

/** The roles a land-search spell plays for the colors it can find. */
function rampRoles(card: SourceCard, colors: string[]): SourceRole[] {
    if (card.cmc <= CHEAP_RAMP_MAX_MV) {
        return [{ colors, weight: CHEAP_RAMP_WEIGHT, minMv: card.cmc + 1 }];
    }
    return [
        { colors, weight: RAMP_EARLY_WEIGHT, minMv: card.cmc + 1 },
        { colors, weight: RAMP_WEIGHT, minMv: card.cmc + 2 }
    ];
}

/**
 * Classify a card into the source roles it plays — none, one, or
 * several when a card both makes mana and searches out a land, or is
 * ramp whose weight grows with the spell it supports.
 *
 * @param card    - The card to classify.
 * @param resolve - Deck-aware pattern resolver from `makeFetchResolver`,
 *                  or null when the deck has no pattern to resolve.
 */
export function sourceRoles(card: SourceCard, resolve: ((pattern: string) => string[]) | null): SourceRole[] {
    if (card.type_line.includes("Land") || card.is_mdfc_land) {
        const colors = card.fetch_pattern && resolve ? resolve(card.fetch_pattern) : (card.produced_mana ?? []);
        return colors.length > 0 ? [{ colors, weight: 1, minMv: 0 }] : [];
    }

    const roles: SourceRole[] = [];
    const producer = producerRole(card);
    if (producer !== null) roles.push(producer);
    if (card.land_search_pattern && resolve) {
        const colors = resolve(card.land_search_pattern);
        if (colors.length > 0) roles.push(...rampRoles(card, colors));
    }
    return roles;
}

/**
 * The role a card counts through toward a spell of mana value `mv` in
 * any of `colors` — the heaviest one that covers one of the colors and
 * is online by then — or null when none does. Roles never stack: a
 * dork that also fetches a land is still one card.
 */
export function bestRole(roles: SourceRole[], colors: readonly string[], mv: number): SourceRole | null {
    let best: SourceRole | null = null;
    for (const role of roles) {
        if ((best !== null && role.weight <= best.weight) || mv < role.minMv) continue;
        if (role.colors.some(c => colors.includes(c))) best = role;
    }
    return best;
}

/** How much of a source one copy is worth — the weight of its {@link bestRole}, or 0. */
export function sourceValue(roles: SourceRole[], colors: readonly string[], mv: number): number {
    return bestRole(roles, colors, mv)?.weight ?? 0;
}
