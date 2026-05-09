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
     *  4. `"a land card"` (no type, no basic) → `any`.
     *
     * Returns NULL if none of the above match. The text is normalized
     * to lowercase + single-space whitespace before pattern-matching
     * so reflow / line-break variations don't break the regexes.
     */
    private function parsePattern(string $oracleText): ?string
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
        if (preg_match('/basic (?:plains|island|swamp|mountain|forest)/', $text)) {
            $colors = $this->collectTypesFromBasicClause($text);
            if ($colors === '') {
                return null;
            }

            return 'basic:'.$colors;
        }

        // 3. Typed "<Type> card" without a "basic" prefix — Onslaught /
        //    Mirage fetches and Krosan-Verge-style two-land searches.
        if (preg_match('/(?:plains|island|swamp|mountain|forest) card/', $text)) {
            $colors = $this->collectTypesFromTypedClause($text);
            if ($colors === '') {
                return null;
            }

            return 'typed:'.$colors;
        }

        // 4. "a land card" — pure tutor with no type filter (Urza's Cave).
        if (preg_match('/library for (?:a|an|up to \w+) land cards?/', $text)) {
            return 'any';
        }

        return null;
    }

    /**
     * Collect every `basic <Type>` mention from the lowercased text and
     * return the unique color letters in WUBRG order. Used by the
     * `basic:<colors>` branch — Panoramas list 3 types
     * (`basic Forest, Plains, or Island`) and Mirage-style Landscapes
     * list 3 (`basic Plains, Swamp, or Mountain`). The repeated word
     * "basic" only appears once per clause; the rest of the types are
     * inferred from the comma- or "or"-separated tail.
     *
     * Implementation note: Scryfall's wording is consistent — the
     * "basic" qualifier applies to the first land type and is
     * implicitly inherited by each subsequent type in the same list.
     * So we capture the first land type after "basic" and then look
     * for additional types separated by `,` or `or` until the closing
     * "card".
     */
    private function collectTypesFromBasicClause(string $text): string
    {
        // Match a "basic <Type1>(, <Type2>)*( or <TypeN>)? card" run.
        if (! preg_match(
            '/basic ((?:plains|island|swamp|mountain|forest)(?:[, ]+(?:or )?(?:plains|island|swamp|mountain|forest))*) cards?/',
            $text,
            $m
        )) {
            return '';
        }
        preg_match_all('/(plains|island|swamp|mountain|forest)/', $m[1], $tm);

        return $this->canonicalizeColors($tm[1]);
    }

    /**
     * Collect every typed-land mention from a "no-basic" clause and
     * return the unique color letters in WUBRG order.
     *
     * Two clause shapes show up in the data:
     *   - One run, multiple types: "an Island or Swamp card"
     *     (Polluted Delta). The shared "card" only follows the LAST
     *     type, so a naive "<Type> card" regex would only catch
     *     `Swamp` and miss `Island`.
     *   - Two runs, one type each, joined by "and a": "a Forest
     *     card and a Plains card" (Krosan Verge).
     *
     * The matcher first locates each `<Type-run> card[s]` block (where
     * a run is one or more types chained by `,` / `or`), then pulls
     * every type letter from each block. Quoting the entire run
     * pre-`card` ensures multi-type "or" clauses contribute every
     * type, not just the trailing one.
     */
    private function collectTypesFromTypedClause(string $text): string
    {
        preg_match_all(
            '/((?:plains|island|swamp|mountain|forest)(?:[, ]+(?:or )?(?:plains|island|swamp|mountain|forest))*)\s+cards?/',
            $text,
            $matches
        );
        $types = [];
        foreach ($matches[1] ?? [] as $run) {
            preg_match_all('/(plains|island|swamp|mountain|forest)/', $run, $tm);
            foreach ($tm[1] ?? [] as $t) {
                $types[] = $t;
            }
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
