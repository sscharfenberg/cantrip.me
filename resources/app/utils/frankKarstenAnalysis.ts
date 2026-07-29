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

/**
 * 60-card recommendation table (Karsten's "60-card deck" column).
 * Lookup is `[pipDensity][cmc]`, both 1-indexed. `null` means the
 * cell is structurally impossible (more pips than cmc) or omitted by
 * Karsten as trivially satisfied.
 */
const TABLE_60: Record<number, Record<number, number | null>> = {
    1: { 1: 14, 2: 13, 3: 12, 4: 10, 5: 9, 6: 9, 7: null },
    2: { 1: null, 2: 21, 3: 18, 4: 16, 5: 15, 6: 13, 7: 12 },
    3: { 1: null, 2: null, 3: 23, 4: 21, 5: 19, 6: 17, 7: 16 },
    4: { 1: null, 2: null, 3: null, 4: 24, 5: 22, 6: null, 7: null }
};

/**
 * 99-card recommendation table (Karsten's "99-card deck" column —
 * the Commander-singleton size). Same lookup shape as `TABLE_60`.
 * Used for every 100-card-deck format we ship.
 */
const TABLE_100: Record<number, Record<number, number | null>> = {
    1: { 1: 19, 2: 19, 3: 18, 4: 16, 5: 15, 6: 14, 7: null },
    2: { 1: null, 2: 30, 3: 28, 4: 26, 5: 23, 6: 22, 7: 20 },
    3: { 1: null, 2: null, 3: 36, 4: 33, 5: 30, 6: 28, 7: 26 },
    4: { 1: null, 2: null, 3: null, 4: 39, 5: 36, 6: null, 7: null }
};

/**
 * Format slugs that play with a 100-card deck (commander-singleton
 * size). Mirrors the PHP `FormatProfile` chain in `app/Formats/`:
 * `CommanderProfile` and `GladiatorProfile` are 100-card; everything
 * else (Standard, Modern, Pioneer, Legacy, Vintage, Pauper, Penny,
 * Premodern, Oldschool, Future, Alchemy, Historic, Timeless,
 * Oathbreaker, StandardBrawl) is 60-card. Unknown / future Scryfall
 * format slugs fall back to 60-card.
 */
const HUNDRED_CARD_FORMATS = new Set(["commander", "duel", "brawl", "paupercommander", "predh", "gladiator"]);

/** Resolves a Scryfall format slug to the deck size that drives table choice. */
const deckSizeFor = (format: string): 60 | 100 => (HUNDRED_CARD_FORMATS.has(format) ? 100 : 60);

/** Mana-symbol token regex — matches `{X}`-style braces in cost strings. */
const SYMBOL_REGEX = /\{([^}]+)}/g;

/** WUBRG, in the canonical display order used everywhere in the app. */
const COLORS: readonly HighlightColor[] = ["W", "U", "B", "R", "G"] as const;

/**
 * One row of the per-color Karsten analysis: how many sources of this
 * color the deck has, how many Karsten recommends for the deck's most
 * demanding card of this color, and the shortfall (zero if sufficient).
 *
 * Karsten's gold-card +1 hack is baked into `need`: any card with ≥2
 * distinct *forced* colored pips (e.g. Teferi `{1}{W}{U}`) bumps each
 * color's individual requirement by +1 — his published rule of thumb
 * for the conditional-probability hit when both colors must appear by
 * the on-curve turn. Pure hybrid (`{W/U}{W/U}`) is NOT gold and does
 * not contribute here at all; its requirement lives in
 * {@link KarstenCombinedAnalysis} instead.
 */
export interface KarstenColorAnalysis {
    color: HighlightColor;
    have: number;
    need: number;
    /** `max(0, need - have)`. Zero ⇒ sufficient. */
    short: number;
}

/**
 * One row of Karsten's "combined" requirement — sources that can
 * produce *any* of a set of colors. Two flavors feed this shape:
 *
 *  - **Gold combined.** A card with ≥2 forced colors (e.g. Teferi
 *    `{1}{W}{U}`) demands `(cmc, totalForcedPips)` lookup +1.
 *  - **Hybrid combined.** A pure-hybrid card (e.g. Yorion
 *    `{3}{W/U}{W/U}`) demands `(cmc, hybridPipCount)` lookup, NO +1
 *    — only one of the colors is needed per pip.
 *
 * `colors` is sorted in WUBRG order. A combo's `need` is the max
 * across every card in the deck that demands that exact color set
 * (whichever flavor wins). Only emitted for color combinations
 * actually demanded by a card in the deck.
 */
export interface KarstenCombinedAnalysis {
    colors: HighlightColor[];
    have: number;
    need: number;
    /** `max(0, need - have)`. Zero ⇒ sufficient. */
    short: number;
}

/**
 * Per-card pip breakdown for Karsten analysis. Distinguishes "forced"
 * (single-color) pips from "hybrid" (multi-color choice) pips because
 * Karsten treats them differently:
 *   - Forced pips set a per-color requirement and trigger the
 *     gold-card +1 hack when ≥2 forced colors are present.
 *   - Hybrid pips set a *combined* requirement only — sources of any
 *     color in the hybrid set, with NO +1 hack since you only need
 *     one of the colors to be available.
 *
 * Phyrexian (`{W/P}`) and twobrid (`{2/W}`) are modeled as forced,
 * since the alternative payment isn't another color (life / generic).
 *
 * Independent from `tallyManaCostInto` in `useDeckStats.ts`, which is
 * the cost-donut helper and intentionally double-counts hybrid.
 */
export interface CardPipBreakdown {
    cmc: number;
    /** Forced single-color pips. Hybrid pips do NOT contribute here. */
    pips: Record<HighlightColor, number>;
    /**
     * One entry per hybrid symbol in the cost. Each entry is the set
     * of colors that pip can be paid with, in WUBRG order. Two
     * `{W/U}` symbols in the same cost produce two entries `["W","U"]`.
     * Empty for cards without hybrid pips.
     */
    hybridGroups: HighlightColor[][];
}

/**
 * Parse all faces of a card's mana cost(s) into the Karsten breakdown
 * shape. Walks each face string for `{...}` symbols and classifies
 * each into either `pips` (forced single-color, including phyrexian
 * and twobrid since their alternative payment isn't a color) or
 * `hybridGroups` (true two-or-more-color hybrid like `{W/U}`).
 *
 * `cmc` is passed through unchanged — the caller already has it from
 * the deck card row and we don't want to re-derive it from cost
 * strings (split / MDFC faces would be ambiguous).
 */
export const breakdownCard = (faces: (string | null)[], cmc: number): CardPipBreakdown => {
    const pips: Record<HighlightColor, number> = { W: 0, U: 0, B: 0, R: 0, G: 0 };
    const hybridGroups: HighlightColor[][] = [];
    for (const face of faces) {
        if (!face) continue;
        for (const match of face.matchAll(SYMBOL_REGEX)) {
            const parts = match[1].split("/");
            const colorParts = parts.filter((p): p is HighlightColor => (COLORS as readonly string[]).includes(p));
            if (colorParts.length === 0) continue;
            // True hybrid: every part of the symbol is a color (e.g.
            // `{W/U}`, `{W/U/G}`). Phyrexian (`{W/P}`) and twobrid
            // (`{2/W}`) fail this test — they have a non-color part —
            // and so fall through as forced.
            if (parts.length >= 2 && colorParts.length === parts.length) {
                hybridGroups.push(COLORS.filter(c => colorParts.includes(c)));
            } else {
                for (const color of colorParts) {
                    pips[color] += 1;
                }
            }
        }
    }
    return { cmc, pips, hybridGroups };
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
 * Canonical TCGplayer URL for Karsten's 2022 consolidated update —
 * the only currently-live source that covers colored-source counts
 * at both deck sizes. Karsten's separate 2020 Brawl/80-card update
 * lived behind Channel Fireball Pro and stopped resolving after the
 * TCGplayer acquisition; if a dedicated Commander-format article
 * resurfaces, point the 100-card branch of `karstenArticleUrl` at it.
 */
const KARSTEN_2022_UPDATE =
    "https://www.tcgplayer.com/content/article/How-Many-Sources-Do-You-Need-to-Consistently-Cast-Your-Spells-A-2022-Update/dc23a7d2-0a16-4c0b-ad36-586fcca03ad8/";

/**
 * Per-format link to the Karsten article powering the recommendation
 * tables. Format-keyed so 60-card and 100-card branches can diverge
 * if a future article splits them — currently both resolve to
 * `KARSTEN_2022_UPDATE`.
 */
export const karstenArticleUrl = (format: string): string =>
    deckSizeFor(format) === 100 ? KARSTEN_2022_UPDATE : KARSTEN_2022_UPDATE;
