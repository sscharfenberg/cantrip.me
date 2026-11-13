<?php

namespace App\Services\Scryfall\OracleTagSyncs;

/**
 * Maps Scryfall's `otag:fetchland` search to
 * `oracle_cards.fetch_pattern` by parsing each card's oracle text
 * into a deck-independent classification. The pattern encodes WHAT
 * the fetch can grab, not the colors it produces — the colors are
 * resolved per-deck on the frontend by walking the deck's other
 * lands and unioning their `produced_mana`.
 *
 * Pattern grammar (always WUBRG-sorted; same vocabulary the
 * frontend's `useDeckStats` reads):
 *
 *   - `'basic'`           — any basic land (Fabled Passage, …)
 *   - `'basic:<WUBRG>'`   — basic of one or more specific subtypes
 *                            (Bant Panorama → `basic:WUG`)
 *   - `'typed:<WUBRG>'`   — typed land basic-or-not
 *                            (Polluted Delta → `typed:UB`)
 *   - `'any'`             — any land card (Urza's Cave)
 *
 * Cards the parser cannot classify return `null` from
 * `deriveValue`; the orchestrator skips them and logs the names so
 * a parser regression can't silently wipe valid data.
 */
class FetchPatternOracleTagSync extends OracleTagSync
{
    /** Land-subtype name → WUBRG color letter. */
    private const TYPE_TO_COLOR = [
        'plains' => 'W',
        'island' => 'U',
        'swamp' => 'B',
        'mountain' => 'R',
        'forest' => 'G',
    ];

    /** Canonical WUBRG order for sorting parsed color letters. */
    private const WUBRG_ORDER = ['W', 'U', 'B', 'R', 'G'];

    /** One basic land type, lowercased. */
    private const TYPE = '(?:plains|island|swamp|mountain|forest)';

    /** A list of basic types: "forest", "island or swamp", "plains, island, swamp, or mountain". */
    private const TYPE_RUN = self::TYPE.'(?:[, ]+(?:or )?'.self::TYPE.')*';

    /**
     * One other type closing a type list — "a Mountain or Cave card",
     * "a Plains, Island, or Cave card" — but not "a Forest or more cards".
     */
    private const OTHER_TYPE = '(?:,? or (?!more\b|fewer\b)[a-z]+)?';

    public function tag(): string
    {
        return 'fetchland';
    }

    public function column(): string
    {
        return 'fetch_pattern';
    }

    public function clearValue(): mixed
    {
        return null;
    }

    /**
     * Pull the front-face oracle text from the Scryfall payload (top
     * level for single-faced lands, `card_faces[0]` for the
     * theoretical MDFC fetchland) and run it through the parser.
     *
     * @param  array<string, mixed>  $card
     */
    public function deriveValue(array $card): ?string
    {
        $text = $card['oracle_text'] ?? ($card['card_faces'][0]['oracle_text'] ?? '');

        return $this->parsePattern((string) $text);
    }

    /**
     * Derive a pattern from a card's oracle text. The matching is
     * intentionally ordered most-specific first:
     *
     *  1. `"basic land card"` (alone) → `basic`.
     *  2. `"basic <Type>"` (one or more) → `basic:<colors>`.
     *  3. `"<Type> card"` without a leading "basic" → `typed:<colors>`.
     *  4. `"a land card with a basic land type"` → `typed:WUBRG`.
     *  5. `"a land card"` (no type, no basic) → `any`.
     *
     * Returns NULL if none of the above match. The text is normalized
     * to lowercase + single-space whitespace before pattern-matching
     * so reflow / line-break variations don't break the regexes.
     *
     * Only the five basic land types are expressible: a list that also
     * names another land type ("a Mountain or Cave card") yields the
     * colours of its basic types only, so a deck's Caves go uncounted.
     */
    protected function parsePattern(string $oracleText): ?string
    {
        $text = strtolower((string) preg_replace('/\s+/', ' ', $oracleText));

        // 1. Pure "basic land card" with no leading type qualifier —
        //    matched first because typed mentions can otherwise hide it.
        if (preg_match('/basic land cards?/', $text)) {
            return 'basic';
        }

        // 2. "basic <Type>" — one or more types separated by `,` / `or`.
        //    Catches Panoramas (`basic Forest, Plains, or Island`) and
        //    Khans-cycle Landscapes (`basic Forest, Island, or Mountain`).
        if (preg_match('/basic '.self::TYPE.'/', $text)) {
            $colors = $this->collectTypesFromBasicClause($text);
            if ($colors === '') {
                return null;
            }

            return 'basic:'.$colors;
        }

        // 3. Typed "<Type> card" without a "basic" prefix — Onslaught /
        //    Mirage fetches and Krosan-Verge-style two-land searches.
        if (preg_match('/'.self::TYPE_RUN.self::OTHER_TYPE.' cards?/', $text)) {
            $colors = $this->collectTypes('/('.self::TYPE_RUN.')'.self::OTHER_TYPE.'\s+cards?/', $text);
            if ($colors === '') {
                return null;
            }

            return 'typed:'.$colors;
        }

        // 4. "a land card with a basic land type" — any land carrying a
        //    basic type, basic or not (Potential Horizon, Perilous Forays).
        //    Ahead of the next rule, whose "a land card" it also matches.
        if (preg_match('/land cards? with a basic land type/', $text)) {
            return 'typed:WUBRG';
        }

        // 5. "a land card" — pure tutor with no type filter (Urza's Cave),
        //    also as one choice among card types ("a creature or land
        //    card", "an artifact, creature, or land card").
        if (preg_match('/library for (?:a|an|up to \w+) (?:[a-z]+(?:, [a-z]+)*,? or )?land cards?/', $text)) {
            return 'any';
        }

        return null;
    }

    /**
     * Collect the `basic <Type>` mentions of the search and return the
     * unique color letters in WUBRG order. Used by the `basic:<colors>`
     * branch — Panoramas list 3 types (`basic Forest, Plains, or Island`)
     * and Mirage-style Landscapes list 3 (`basic Plains, Swamp, or
     * Mountain`). The repeated word "basic" only appears once per clause;
     * the rest of the types are inferred from the comma- or "or"-separated
     * tail.
     *
     * A search can name several clauses, each with its own "basic" ("a
     * basic Forest card and a basic Plains card" — Yasharn), so every
     * clause of the sentence holding the first one counts. Other
     * sentences do not: the fetchland sync hands over a land's whole
     * text, where a "basic Plains card" elsewhere is not what it fetches.
     */
    private function collectTypesFromBasicClause(string $text): string
    {
        foreach (preg_split('/(?<=\.)\s+/', $text) ?: [] as $sentence) {
            if (preg_match('/basic '.self::TYPE.'/', $sentence)) {
                return $this->collectTypes('/basic ('.self::TYPE_RUN.') cards?/', $sentence);
            }
        }

        return '';
    }

    /**
     * Collect the land types of every type run $pattern captures (group
     * 1) and return the unique color letters in WUBRG order.
     *
     * Typed clauses come in two shapes:
     *   - One run, multiple types: "an Island or Swamp card"
     *     (Polluted Delta). The shared "card" only follows the LAST
     *     type, so a naive "<Type> card" regex would only catch
     *     `Swamp` and miss `Island`.
     *   - Two runs, one type each, joined by "and a": "a Forest
     *     card and a Plains card" (Krosan Verge).
     *
     * Capturing the entire run before `card` ensures multi-type "or"
     * clauses contribute every type, not just the trailing one.
     */
    private function collectTypes(string $pattern, string $text): string
    {
        preg_match_all($pattern, $text, $matches);
        $types = [];
        foreach ($matches[1] as $run) {
            preg_match_all('/(plains|island|swamp|mountain|forest)/', $run, $tm);
            array_push($types, ...$tm[1]);
        }

        return $this->canonicalizeColors($types);
    }

    /**
     * Map land-type names to WUBRG color letters, dedupe, and sort
     * into canonical WUBRG order.
     *
     * @param  list<string>  $types
     */
    private function canonicalizeColors(array $types): string
    {
        $colors = [];
        foreach ($types as $type) {
            $color = self::TYPE_TO_COLOR[strtolower($type)] ?? null;
            if ($color !== null && ! in_array($color, $colors, true)) {
                $colors[] = $color;
            }
        }
        usort($colors, fn ($a, $b) => array_search($a, self::WUBRG_ORDER, true) <=> array_search($b, self::WUBRG_ORDER, true));

        return implode('', $colors);
    }
}
