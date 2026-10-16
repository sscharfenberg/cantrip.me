<?php

namespace App\Services\Scryfall\OracleTagSyncs;

/**
 * Maps Scryfall's `otag:tutor-land` search, narrowed to non-land cards,
 * to `oracle_cards.land_search_pattern`. These are the spells and
 * permanents that fetch a land for their controller — Three Visits
 * (`typed:G`), Farseek (`typed:WUBR`), Rampant Growth and Cultivate
 * (`basic`), Sakura-Tribe Elder (`basic`) — and the deck stats count
 * them as partial colour sources for whatever the deck's lands let
 * them find.
 *
 * The oracle-text parse and its pattern grammar are inherited from
 * {@see FetchPatternOracleTagSync} unchanged; only the source of the
 * cards and the text handed to the parser differ.
 *
 * Why the query is narrowed rather than the whole tag taken:
 *
 *   - `-type:land` — lands stay on the curated `otag:fetchland` sync.
 *     The wider tag also carries Ghost Quarter and Flagstones of
 *     Trokair, which would otherwise be classified as fetchlands.
 *   - `o:"search your library"` — Scryfall's `o:` skips reminder text,
 *     so landcyclers (whose search lives only in the cycling reminder)
 *     drop out, and so does removal that hands the *opponent* a land
 *     (Path to Exile: "its controller may search their library").
 */
class LandSearchPatternOracleTagSync extends FetchPatternOracleTagSync
{
    public function tag(): string
    {
        return 'tutor-land';
    }

    public function query(): string
    {
        return 'otag:tutor-land -type:land o:"search your library"';
    }

    public function column(): string
    {
        return 'land_search_pattern';
    }

    /**
     * The tag cannot tell the deck stats apart from these. Its unparsed
     * remainder (Scapeshift, Into the North, the search sentences that
     * end in exile) is the normal state, not a parser regression.
     */
    public function expectsUnclassified(): bool
    {
        return true;
    }

    /**
     * Parse only the searches that deliver the land somewhere it can make
     * mana — onto the battlefield (Rampant Growth) or into the hand (Land
     * Tax, The Birth of Meletis, which Karsten counts as ramp). A search
     * that exiles the land (Strata Scythe) or stacks it on the library
     * (Treefolk Harbinger) supplies no colour, and parsing the whole text
     * would still find its "land card".
     *
     * A search runs from its "search your library for" sentence through
     * the sentence that shuffles, because the destination is often the
     * next sentence ("…and reveal them. Put them onto the battlefield
     * tapped, then shuffle.") or two on (Tithe's conditional second
     * search sits in between).
     *
     * Every face is read, unlike the fetchland sync: the search often
     * sits on the second face (Bloomvine Regent's omen, a saga's back).
     * Reminder text is stripped first because a card can carry both a
     * real search and a landcycling reminder, and the reminder's land
     * type is not one the spell itself can find.
     *
     * @param  array<string, mixed>  $card
     */
    public function deriveValue(array $card): ?string
    {
        $text = $card['oracle_text']
            ?? implode("\n", array_map(fn (array $face) => $face['oracle_text'] ?? '', $card['card_faces'] ?? []));
        $text = (string) preg_replace('/\([^)]*\)/', '', (string) $text);
        $sentences = preg_split('/(?<=\.)\s+/', trim($text)) ?: [];

        $searches = [];
        for ($i = 0, $count = count($sentences); $i < $count; $i++) {
            if (! preg_match('/\bsearch your library for\b/i', $sentences[$i])) {
                continue;
            }
            $window = [];
            for ($j = $i; $j < $count; $j++) {
                $window[] = $sentences[$j];
                if (preg_match('/\bshuffle\b/i', $sentences[$j])) {
                    break;
                }
            }
            $i = $j;
            $search = implode(' ', $window);
            if (preg_match('/onto the battlefield|into your hand/i', $search)) {
                $searches[] = $search;
            }
        }

        return $searches === [] ? null : $this->parsePattern(implode(' ', $searches));
    }
}
