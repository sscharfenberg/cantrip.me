import type { HighlightColor } from "Composables/useDeckHighlight.ts";

/**
 * Frank Karsten's mana-source recommendation tables, read directly from
 * the bolded-green cells of his 2022 update tables (the canonical
 * article linked from the on-page attribution).
 *
 * The bolded-green cell in each column is "the minimum number of
 * sources to consistently cast a card on curve" — meaning 90% probability
 * for one-drops, 91% for two-drops, ..., scaling +1 percentage point
 * per turn (Karsten's sliding confidence threshold).
 *
 * `null` cells are either:
 *   - structurally impossible (e.g. `{C}{C}` at CMC 1), or
 *   - omitted from Karsten's tables (e.g. `{6}{C}` at CMC 7 — by then
 *     you have so many turns to find one source that the recommendation
 *     is trivial; Karsten skipped publishing it).
 *
 * Lookup is `[pipDensity][cmc]`. pipDensity is the count of colored
 * pips of *one* color in the cost (e.g. `{1}{W}{W}` is pipDensity 2
 * for W). CMC is 1-indexed and capped at 7.
 */

const TABLE_60: Record<number, Record<number, number | null>> = {
    1: { 1: 14, 2: 13, 3: 12, 4: 10, 5: 9, 6: 8, 7: null },
    2: { 1: null, 2: 21, 3: 18, 4: 18, 5: 14, 6: 13, 7: 12 },
    3: { 1: null, 2: null, 3: 23, 4: 21, 5: 19, 6: 19, 7: 16 },
    4: { 1: null, 2: null, 3: null, 4: 24, 5: 22, 6: null, 7: null },
};

const TABLE_100: Record<number, Record<number, number | null>> = {
    1: { 1: 19, 2: 19, 3: 18, 4: 16, 5: 15, 6: 14, 7: null },
    2: { 1: null, 2: 30, 3: 28, 4: 26, 5: 23, 6: 22, 7: 20 },
    3: { 1: null, 2: null, 3: 36, 4: 33, 5: 30, 6: 28, 7: 26 },
    4: { 1: null, 2: null, 3: null, 4: 39, 5: 36, 6: null, 7: null },
};

/**
 * Format → deck size mapping. Mirrors the PHP `FormatProfile` chain in
 * `app/Formats/`: 100-card formats use `CommanderProfile` /
 * `GladiatorProfile`; everything else (Standard, Modern, Pioneer,
 * Legacy, Vintage, Pauper, Penny, Premodern, Oldschool, Future,
 * Alchemy, Historic, Timeless, Oathbreaker, StandardBrawl) is 60-card.
 * Unknown / future Scryfall formats fall back to 60-card.
 */
const HUNDRED_CARD_FORMATS = new Set(["commander", "duel", "brawl", "paupercommander", "predh", "gladiator"]);

const deckSizeFor = (format: string): 60 | 100 => (HUNDRED_CARD_FORMATS.has(format) ? 100 : 60);

/**
 * Per-color pip count and CMC for a single card. Hybrid (`{W/U}`),
 * phyrexian (`{W/P}`), and twobrid (`{2/W}`) all add 1 to each color
 * letter present in the symbol — matches the deck-stats donut and the
 * card highlight matcher (`consumesColor`).
 */
const SYMBOL_REGEX = /\{([^}]+)}/g;
const COLORS: readonly HighlightColor[] = ["W", "U", "B", "R", "G"] as const;

export interface CardPipBreakdown {
    cmc: number;
    pips: Record<HighlightColor, number>;
}

export const breakdownCard = (faces: (string | null)[], cmc: number): CardPipBreakdown => {
    const pips: Record<HighlightColor, number> = { W: 0, U: 0, B: 0, R: 0, G: 0 };
    for (const face of faces) {
        if (!face) continue;
        for (const match of face.matchAll(SYMBOL_REGEX)) {
            for (const part of match[1].split("/")) {
                if ((COLORS as readonly string[]).includes(part)) {
                    pips[part as HighlightColor] += 1;
                }
            }
        }
    }
    return { cmc, pips };
};

/**
 * How many sources of `color` Karsten recommends for a card with this
 * CMC and pip density. Returns `null` for the impossible quadrant
 * (more pips than CMC), or for pipDensity > 4 (rare enough that we
 * cap; a `{W}{W}{W}{W}{W}` cost is treated as `{W}{W}{W}{W}`).
 */
export const sourcesNeeded = (format: string, cmc: number, pipDensity: number): number | null => {
    if (pipDensity <= 0) return null;
    const cappedPips = Math.min(4, pipDensity);
    const cappedCmc = Math.max(1, Math.min(7, Math.floor(cmc)));
    const table = deckSizeFor(format) === 100 ? TABLE_100 : TABLE_60;
    return table[cappedPips]?.[cappedCmc] ?? null;
};

/**
 * Per-format link to the canonical Karsten article powering the
 * recommendation table. Both branches currently point to Karsten's
 * 2022 consolidated update on TCGplayer — the only currently-live URL
 * that covers colored-source counts at both deck sizes.
 *
 * Karsten published a separate Brawl/80-card update in 2020 behind
 * Channel Fireball Pro; that link no longer resolves after the
 * TCGplayer acquisition. If a dedicated Commander-format article
 * resurfaces, swap the 100-card branch and the attribution flips
 * automatically.
 */
const KARSTEN_2022_UPDATE =
    "https://www.tcgplayer.com/content/article/How-Many-Sources-Do-You-Need-to-Consistently-Cast-Your-Spells-A-2022-Update/dc23a7d2-0a16-4c0b-ad36-586fcca03ad8/";

export const karstenArticleUrl = (format: string): string =>
    deckSizeFor(format) === 100 ? KARSTEN_2022_UPDATE : KARSTEN_2022_UPDATE;
