/**
 * Deck-aware fetchland resolver. Translates a deck-independent
 * `fetch_pattern` (stored on `oracle_cards.fetch_pattern` and synced
 * from Scryfall's `otag:fetchland` by `OracleTagsService`) into the
 * effective WUBRG colors a fetchland actually produces in *this*
 * deck — by walking the deck's other lands and unioning their
 * `produced_mana`.
 *
 * Called from both:
 *   - `useDeckStats` (donut production + Karsten "have" calculation)
 *   - `useDeckHighlight` (`.highlighted` class on the card views when
 *      the user clicks a production-ring segment)
 *
 * Both consumers share one bucket build per render so the substring
 * scan against `type_line` happens once, not per fetchland card.
 */

/** WUBRG colors the resolver supports. Module-private; exported users use {@link ColorLetter}. */
const COLORS = ["W", "U", "B", "R", "G"] as const;

/** A single uppercase WUBRG letter, matching the highlight composable's `HighlightColor`. */
export type ColorLetter = (typeof COLORS)[number];

/**
 * WUBRG → matching land subtype name. Used to translate a stored
 * pattern (`typed:UB` → `["Island", "Swamp"]`) into substring checks
 * against `type_line`.
 */
const COLOR_TO_LAND_TYPE: Record<ColorLetter, string> = {
    W: "Plains",
    U: "Island",
    B: "Swamp",
    R: "Mountain",
    G: "Forest"
};

/**
 * Land row used by the fetchland resolver. Strict subset of any
 * deck-card shape — anything with these three fields can back a
 * fetchland match.
 */
export interface LandCandidate {
    type_line: string;
    is_basic_land: boolean;
    produced_mana: string[] | null;
}

/**
 * Pre-bucketed view of the deck's lands, keyed by the dimensions a
 * fetch pattern can ask about. Built once per render by
 * {@link buildFetchBuckets} so the per-card resolve step is O(combo
 * size) rather than O(land count × fetchland count).
 *
 *   - `anyLand` — union of every land's produced colors (drives `any`).
 *   - `anyBasic` — same, restricted to basic lands (drives `basic`).
 *   - `byType[c]` — union of produced colors across lands whose
 *     type_line includes the land subtype matching color `c` (drives
 *     `typed:<colors>`).
 *   - `byBasicType[c]` — same but basic-only (drives `basic:<colors>`).
 */
export interface FetchBuckets {
    anyLand: Set<string>;
    anyBasic: Set<string>;
    byType: Record<ColorLetter, Set<string>>;
    byBasicType: Record<ColorLetter, Set<string>>;
}

/**
 * Walk the deck's lands once and classify each into the bucket(s) it
 * belongs to. Each land contributes its `produced_mana` to:
 *   - `anyLand` (and `anyBasic` if basic),
 *   - one entry of `byType` per land subtype it carries (Triome lands
 *     hit three buckets),
 *   - the matching `byBasicType` entry if it's also basic.
 *
 * Substring matching against `type_line` correctly handles
 * Snow-Covered basics (`"Basic Snow Land — Plains"`), shocklands
 * (`"Land — Plains Island"`), and Triome / type-stacking lands.
 */
export function buildFetchBuckets(lands: LandCandidate[]): FetchBuckets {
    const buckets: FetchBuckets = {
        anyLand: new Set(),
        anyBasic: new Set(),
        byType: { W: new Set(), U: new Set(), B: new Set(), R: new Set(), G: new Set() },
        byBasicType: { W: new Set(), U: new Set(), B: new Set(), R: new Set(), G: new Set() }
    };
    for (const land of lands) {
        if (!land.produced_mana) continue;
        for (const c of land.produced_mana) {
            buckets.anyLand.add(c);
            if (land.is_basic_land) buckets.anyBasic.add(c);
        }
        for (const color of COLORS) {
            if (!land.type_line.includes(COLOR_TO_LAND_TYPE[color])) continue;
            for (const c of land.produced_mana) {
                buckets.byType[color].add(c);
                if (land.is_basic_land) buckets.byBasicType[color].add(c);
            }
        }
    }
    return buckets;
}

/**
 * Resolve a single fetch pattern against the pre-computed buckets.
 * O(combo-size) — at most 5 set unions for a 5-color `typed:WUBRG`.
 *
 * Pattern grammar (set by `scryfall:oracle-tags` from
 * `oracle_cards.fetch_pattern`):
 *
 *   - `'basic'`         — every basic land in the deck
 *   - `'basic:<WUBRG>'` — basics of one or more specific subtypes
 *   - `'typed:<WUBRG>'` — any land (basic or non-basic) of those subtypes
 *   - `'any'`           — every land in the deck (Urza's Cave)
 *   - anything else     — defensive empty result
 *
 * Returned colors include `'C'` (from e.g. Wastes) so callers stay
 * symmetric with raw `produced_mana`; downstream WUBRG-only views
 * filter colorless out of the tally.
 */
export function resolveFetchPattern(pattern: string, buckets: FetchBuckets): string[] {
    if (pattern === "basic") return [...buckets.anyBasic];
    if (pattern === "any") return [...buckets.anyLand];

    const out = new Set<string>();
    if (pattern.startsWith("basic:")) {
        for (const color of parsePatternColors(pattern.slice(6))) {
            for (const c of buckets.byBasicType[color]) out.add(c);
        }
        return [...out];
    }
    if (pattern.startsWith("typed:")) {
        for (const color of parsePatternColors(pattern.slice(6))) {
            for (const c of buckets.byType[color]) out.add(c);
        }
        return [...out];
    }
    return [];
}

/**
 * Parse the WUBRG-letter tail of a fetch pattern (`'WUG'` →
 * `['W','U','G']`). Defensive against unknown letters — they're
 * silently dropped. Empty input returns an empty array.
 */
export function parsePatternColors(tail: string): ColorLetter[] {
    const out: ColorLetter[] = [];
    for (const ch of tail) {
        if ((COLORS as readonly string[]).includes(ch)) {
            out.push(ch as ColorLetter);
        }
    }
    return out;
}

/**
 * Convenience builder that wraps {@link buildFetchBuckets} +
 * {@link resolveFetchPattern} in a memoized closure. Returns null
 * when the deck has zero fetchlands so callers can short-circuit
 * to the raw `produced_mana` path with no work.
 *
 * The returned closure caches per pattern, so a deck with multiple
 * fetchlands sharing a pattern (e.g. Fabled Passage + Evolving Wilds,
 * both `basic`) only resolves once.
 */
export function makeFetchResolver(
    cards: { fetch_pattern?: string | null }[],
    lands: LandCandidate[]
): ((pattern: string) => string[]) | null {
    if (!cards.some(c => c.fetch_pattern)) return null;
    const buckets = buildFetchBuckets(lands);
    const cache = new Map<string, string[]>();
    return (pattern: string): string[] => {
        const cached = cache.get(pattern);
        if (cached !== undefined) return cached;
        const resolved = resolveFetchPattern(pattern, buckets);
        cache.set(pattern, resolved);
        return resolved;
    };
}
