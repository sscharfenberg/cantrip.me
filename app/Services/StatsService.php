<?php

namespace App\Services;

use App\Enums\DeckState;
use App\Models\Deck;
use App\Models\User;

/**
 * Aggregates user-level overview stats for "stats donut" tiles.
 *
 * Currently surfaces the decks list page header (formats / states /
 * modes / total worth); the collection side is queued behind the same
 * service so the collapse rule and shared helpers stay in one place.
 *
 * Shared responsibilities, regardless of domain:
 *   - The "top 9 + Other" collapse rule via
 *     {@see self::collapseToTopWithOther} so a donut never renders
 *     more than {@see self::MAX_SLICES} slices.
 *   - The {@see self::OTHER_KEY} sentinel for the bundled tail slice.
 *
 * Decks-specific (today):
 *   - {@see self::forUserDecks} returns counts across every deck a
 *     user owns, active *and* archived. Counting archived decks is
 *     intentional: the state donut needs the Archived bucket, and
 *     counting consistently keeps every tile aligned with what the
 *     user thinks of as "their decks".
 */
class StatsService
{
    /**
     * Sentinel key for the bundled "Other" slice that appears when a
     * stat has more distinct values than {@see self::MAX_SLICES}.
     * Exposed as a constant so the frontend can locale-translate this
     * key without re-hardcoding the string.
     */
    public const OTHER_KEY = 'other';

    /**
     * Maximum number of slices a donut renders. When the raw input has
     * more distinct keys than this, the top (MAX_SLICES - 1) by count
     * are kept and the remaining counts collapse into one OTHER_KEY
     * slice — total slice count is then exactly MAX_SLICES.
     */
    private const MAX_SLICES = 10;

    /**
     * Build the decks-list-page stats payload for a user.
     *
     * `colors` counts how many of the user's decks have each WUBRG
     * letter in their color identity — a 5-color deck contributes 1 to
     * every color, a mono-blue deck contributes 1 to U only. Colorless
     * decks contribute nothing. Slices therefore don't sum to total
     * deck count; the donut measures color *presence*, not deck count.
     *
     * `avgWorth` / `medianWorth` are derived from the same per-deck
     * worth aggregation as `totalWorth`, so the three numbers can't
     * disagree.
     *
     * @return array{
     *     totalDecks: int,
     *     totalWorth: float,
     *     avgWorth: float,
     *     medianWorth: float,
     *     formats: array<string, int>,
     *     states: array<string, int>,
     *     modes: array<string, int>,
     *     colors: array<string, int>,
     * }
     */
    public static function forUserDecks(User $user): array
    {
        $decks = Deck::query()
            ->where('user_id', $user->id)
            ->get(['id', 'format', 'state', 'collection_mode', 'colors']);

        // Seed states/modes with every enum value so the legend can
        // render a complete list even when the user has zero decks in
        // one of the buckets. Formats start empty because the legend
        // only surfaces formats the user actually plays.
        $formats = [];
        $states = [
            DeckState::Planned->value => 0,
            DeckState::Built->value => 0,
            DeckState::Archived->value => 0,
        ];
        $modes = ['A' => 0, 'B' => 0, 'C' => 0];
        // Seed all five colors so the legend keeps a stable WUBRG order
        // even when a color sees zero use — the frontend filters zero
        // counts when rendering slices but keeps them in legend order.
        $colors = ['W' => 0, 'U' => 0, 'B' => 0, 'R' => 0, 'G' => 0];

        foreach ($decks as $deck) {
            $formatKey = $deck->format->value;
            $formats[$formatKey] = ($formats[$formatKey] ?? 0) + 1;
            $states[$deck->state->value] = ($states[$deck->state->value] ?? 0) + 1;
            $modeKey = $deck->collection_mode ?: 'A';
            $modes[$modeKey] = ($modes[$modeKey] ?? 0) + 1;
            // Walk the color identity string letter-by-letter. Missing
            // / colorless decks (`null`) drop out — `str_split('')`
            // would yield an empty array, so the foreach is a no-op.
            foreach (str_split((string) ($deck->colors ?? '')) as $letter) {
                if (isset($colors[$letter])) {
                    $colors[$letter]++;
                }
            }
        }

        $perDeckTotals = DeckWorthService::perDeckTotals($user, $decks->pluck('id')->all());

        return [
            'totalDecks' => $decks->count(),
            'totalWorth' => round($perDeckTotals->sum(), 2),
            'avgWorth' => self::averageWorth($perDeckTotals->all()),
            'medianWorth' => self::medianWorth($perDeckTotals->all()),
            'formats' => self::collapseToTopWithOther($formats),
            'states' => self::collapseToTopWithOther($states),
            'modes' => self::collapseToTopWithOther($modes),
            'colors' => $colors,
        ];
    }

    /**
     * Mean per-deck worth, rounded to two decimals for display. Empty
     * input returns 0.0; the frontend hides the tile until the user
     * has at least one deck anyway, but the safe zero keeps the
     * payload shape consistent.
     *
     * @param  array<int, float>  $totals
     */
    private static function averageWorth(array $totals): float
    {
        if ($totals === []) {
            return 0.0;
        }

        return round(array_sum($totals) / count($totals), 2);
    }

    /**
     * Median per-deck worth — the middle value (or average of the two
     * middle values for even-length input), rounded to two decimals.
     *
     * Less skew-sensitive than the mean: one expensive Vintage deck
     * doesn't drag the central tendency the way it does the average.
     *
     * @param  array<int, float>  $totals
     */
    private static function medianWorth(array $totals): float
    {
        if ($totals === []) {
            return 0.0;
        }

        $sorted = $totals;
        sort($sorted);
        $count = count($sorted);
        $middle = (int) ($count / 2);

        $median = $count % 2 === 1
            ? $sorted[$middle]
            : ($sorted[$middle - 1] + $sorted[$middle]) / 2;

        return round($median, 2);
    }

    /**
     * Collapse a count map to at most {@see self::MAX_SLICES} entries.
     *
     * - When the input has ≤ MAX_SLICES entries, returned unchanged
     *   (zero-count entries are kept in place so the legend can render
     *   the full enum for states / modes).
     * - When the input has > MAX_SLICES entries, sorts by count desc
     *   (PHP's `arsort` is stable from 8.0+, so equal-count entries
     *   keep their original insertion order), keeps the top
     *   (MAX_SLICES - 1) entries, and bundles the rest into one entry
     *   keyed {@see self::OTHER_KEY}.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private static function collapseToTopWithOther(array $counts): array
    {
        if (count($counts) <= self::MAX_SLICES) {
            return $counts;
        }

        arsort($counts);

        $top = array_slice($counts, 0, self::MAX_SLICES - 1, true);
        $other = array_sum(array_slice($counts, self::MAX_SLICES - 1, null, true));

        $top[self::OTHER_KEY] = $other;

        return $top;
    }
}
