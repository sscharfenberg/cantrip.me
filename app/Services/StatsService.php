<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\DeckState;
use App\Enums\Locale;
use App\Enums\Scryfall\ScryfallRarity;
use App\Formats\FormatProfile;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Aggregates overview stats for "stats donut" tiles.
 *
 * Today surfaces deck-side stats (per-user + site-wide) and the
 * site-wide collection block on the welcome page. All payloads share
 * the same conventions:
 *
 * Shared responsibilities, regardless of domain:
 *   - The "top 9 + Other" collapse rule via
 *     {@see self::collapseToTopWithOther} so a donut never renders
 *     more than {@see self::MAX_SLICES} slices.
 *   - The {@see self::OTHER_KEY} sentinel for the bundled tail slice.
 *
 * Decks:
 *   - {@see self::forUserDecks} aggregates across every deck a user
 *     owns, active *and* archived. Counting archived decks is
 *     intentional: the state donut needs the Archived bucket, and
 *     counting consistently keeps every tile aligned with what the
 *     user thinks of as "their decks".
 *   - {@see self::forSiteDecks} aggregates across every deck in the
 *     database — drives the welcome-page "cantrip.me decks" section.
 *     The viewer parameter (nullable, since the welcome page is open
 *     to guests) only picks the currency for worth-denominated tiles.
 *
 * Collection:
 *   - {@see self::forSiteCollection} aggregates across every
 *     `card_stacks` row + every `containers` row in the database —
 *     drives the welcome-page "cantrip.me collections" section.
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
     * Stats payload for the decks-list-page header (scoped to one
     * user, all of their decks active + archived).
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

        return self::aggregateDeckStats($decks, $user);
    }

    /**
     * Stats payload for the welcome-page "cantrip.me decks" section
     * (every deck in the database, regardless of owner). The viewer is
     * passed straight through to {@see DeckWorthService::perDeckTotals}
     * to pick the currency for worth-denominated tiles — null when a
     * guest visits.
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
    public static function forSiteDecks(?User $viewer): array
    {
        $decks = Deck::query()
            ->get(['id', 'format', 'state', 'collection_mode', 'colors']);

        return self::aggregateDeckStats($decks, $viewer);
    }

    /**
     * Shared deck aggregation — counts the distribution buckets and
     * calls into {@see DeckWorthService} for the worth tiles. The
     * `worthViewer` parameter is nullable so guest visitors on the
     * welcome page fall back to the locale's default currency.
     *
     * `colors` counts how many decks have each WUBRG letter in their
     * color identity — a 5-color deck contributes 1 to every color, a
     * mono-blue deck contributes 1 to U only. Colorless decks
     * contribute nothing. Slices therefore don't sum to total deck
     * count; the donut measures color *presence*, not deck count.
     *
     * `avgWorth` / `medianWorth` are derived from the same per-deck
     * totals as `totalWorth`, so the three numbers can't disagree.
     *
     * @param  EloquentCollection<int, Deck>  $decks
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
    private static function aggregateDeckStats(EloquentCollection $decks, ?User $worthViewer): array
    {
        // Seed states/modes with every enum value so the legend can
        // render a complete list even when one bucket sees zero
        // decks. Formats start empty because the legend only surfaces
        // formats actually represented in the dataset.
        $formats = [];
        $states = [
            DeckState::Planned->value => 0,
            DeckState::Built->value => 0,
            DeckState::Archived->value => 0,
        ];
        $modes = ['A' => 0, 'B' => 0, 'C' => 0];
        // Seed all five colors so the legend keeps a stable WUBRG
        // order even when a color sees zero use.
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

        $perDeckTotals = DeckWorthService::perDeckTotals($worthViewer, $decks->pluck('id')->all());

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
     * Stats payload for the welcome-page "cantrip.me collections"
     * section. The viewer parameter picks the currency for worth-
     * denominated values; nullable because guests visit the welcome
     * page.
     *
     * @return array{
     *     totalCards: int,
     *     uniqueCards: int,
     *     containers: int,
     *     totalPrice: float,
     *     containerTypes: array<string, int>,
     *     rarities: array<string, int>,
     *     topSets: array<int, array{code: string, name: string, count: int}>,
     *     mostValuableCard: array{name: string, set_code: string, card_image_0: string|null, price: float}|null,
     *     mostOwnedCard: array{name: string, set_code: string, card_image_0: string|null, owned: int}|null,
     * }
     */
    public static function forSiteCollection(?User $viewer): array
    {
        $currency = $viewer?->currency
            ?? Locale::from(app()->getLocale())->defaultCurrency();
        $unitPriceSql = ContainerService::unitPriceSql($currency);

        // Single aggregate query for the totals + rarity buckets so
        // the welcome-page collection tiles can't disagree with each
        // other if the dataset changes mid-render.
        $totals = CardStack::query()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->selectRaw('COALESCE(SUM(card_stacks.amount), 0) as total_cards')
            ->selectRaw('COUNT(DISTINCT default_cards.id) as unique_cards')
            ->selectRaw("COALESCE(SUM(card_stacks.amount * ({$unitPriceSql})), 0) as total_price")
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as commons', [ScryfallRarity::Common->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as uncommons', [ScryfallRarity::Uncommon->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as rares', [ScryfallRarity::Rare->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as mythics', [ScryfallRarity::Mythic->value])
            ->first();

        $containerTypes = Container::query()
            ->selectRaw('type, COUNT(*) as cnt')
            ->groupBy('type')
            ->pluck('cnt', 'type')
            ->map(fn ($cnt): int => (int) $cnt)
            ->all();

        return [
            'totalCards' => (int) $totals->total_cards,
            'uniqueCards' => (int) $totals->unique_cards,
            'containers' => array_sum($containerTypes),
            'totalPrice' => (float) $totals->total_price,
            // Container types are bounded by the ContainerType enum
            // (currently 8 cases), so the collapse rule is effectively
            // a no-op here — applied for consistency with the donut
            // payload contract.
            'containerTypes' => self::collapseToTopWithOther($containerTypes),
            'rarities' => [
                ScryfallRarity::Common->value => (int) $totals->commons,
                ScryfallRarity::Uncommon->value => (int) $totals->uncommons,
                ScryfallRarity::Rare->value => (int) $totals->rares,
                ScryfallRarity::Mythic->value => (int) $totals->mythics,
            ],
            'topSets' => self::topSets(),
            'mostValuableCard' => self::mostValuableCard($currency),
            'mostOwnedCard' => self::mostOwnedCard(),
        ];
    }

    /**
     * Top five most-collected sets by total card amount across every
     * user's stacks. Returned in descending order. Capped at five and
     * explicitly *not* run through the collapser — this is a "top N
     * leaderboard" tile, not a distribution, so a bundled "Other"
     * slice would mislead the reader.
     *
     * @return array<int, array{code: string, name: string, count: int}>
     */
    private static function topSets(): array
    {
        return CardStack::query()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->join('sets', 'default_cards.set_id', '=', 'sets.id')
            ->groupBy('sets.id', 'sets.code', 'sets.name')
            ->selectRaw('sets.code as code, sets.name as name, COALESCE(SUM(card_stacks.amount), 0) as cnt')
            ->orderByRaw('cnt desc')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'count' => (int) $row->cnt,
            ])
            ->all();
    }

    /**
     * The single most-valuable card *currently owned* by any user, in
     * the visitor's currency. "Owned" means it appears in any non-
     * proxy `card_stacks` row; proxies are excluded because their
     * unit price short-circuits to 0.
     *
     * Returns null when nobody owns a positively-priced card (empty
     * database, or every owned printing has a null/zero price in the
     * requested currency).
     *
     * @return array{name: string, set_code: string, card_image_0: string|null, price: float}|null
     */
    private static function mostValuableCard(Currency $currency): ?array
    {
        $unitPriceSql = ContainerService::unitPriceSql($currency);

        $row = CardStack::query()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->join('sets', 'default_cards.set_id', '=', 'sets.id')
            ->where('card_stacks.proxy', false)
            ->selectRaw('default_cards.name as name')
            ->selectRaw('default_cards.card_image_0 as card_image_0')
            ->selectRaw('sets.code as set_code')
            ->selectRaw("({$unitPriceSql}) as unit_price")
            ->orderByRaw("({$unitPriceSql}) desc")
            ->limit(1)
            ->first();

        if ($row === null || (float) $row->unit_price <= 0) {
            return null;
        }

        return [
            'name' => (string) $row->name,
            'set_code' => (string) $row->set_code,
            'card_image_0' => $row->card_image_0 !== null ? (string) $row->card_image_0 : null,
            'price' => (float) $row->unit_price,
        ];
    }

    /**
     * The single most-owned card across every user's stacks. Basic
     * lands are excluded — without the filter, this tile would
     * trivially read "Forest, 14000 copies" for any active site.
     *
     * Basics are identified by `default_cards.name` since basic-land
     * printings consistently use the bare basic name (no double-faced
     * shenanigans), so the lookup avoids the oracle_cards join.
     *
     * Returns null when nobody owns any non-basic card.
     *
     * @return array{name: string, set_code: string, card_image_0: string|null, owned: int}|null
     */
    private static function mostOwnedCard(): ?array
    {
        $row = CardStack::query()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->join('sets', 'default_cards.set_id', '=', 'sets.id')
            ->whereNotIn('default_cards.name', FormatProfile::BASIC_LANDS)
            ->groupBy('default_cards.id', 'default_cards.name', 'default_cards.card_image_0', 'sets.code')
            ->selectRaw('default_cards.name as name')
            ->selectRaw('default_cards.card_image_0 as card_image_0')
            ->selectRaw('sets.code as set_code')
            ->selectRaw('COALESCE(SUM(card_stacks.amount), 0) as owned')
            ->orderByRaw('COALESCE(SUM(card_stacks.amount), 0) desc')
            ->limit(1)
            ->first();

        if ($row === null || (int) $row->owned <= 0) {
            return null;
        }

        return [
            'name' => (string) $row->name,
            'set_code' => (string) $row->set_code,
            'card_image_0' => $row->card_image_0 !== null ? (string) $row->card_image_0 : null,
            'owned' => (int) $row->owned,
        ];
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
