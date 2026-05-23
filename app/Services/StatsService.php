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
     * section (every card_stack + container in the database). The
     * viewer parameter picks the currency for worth-denominated values;
     * nullable because guests visit the welcome page.
     */
    public static function forSiteCollection(?User $viewer): array
    {
        return self::aggregateCollectionStats(null, $viewer);
    }

    /**
     * Stats payload for the per-user collection page. Same shape as
     * {@see self::forSiteCollection}; queries are scoped to this
     * user's `card_stacks` and `containers` only.
     */
    public static function forUserCollection(User $user): array
    {
        return self::aggregateCollectionStats($user, $user);
    }

    /**
     * Shared collection aggregation. When `$scope` is null, every row
     * in the database participates (welcome page); otherwise queries
     * are restricted to that user's stacks/containers (collection
     * page). The `$viewer` is independent so the welcome page can
     * resolve a guest's currency from the locale.
     *
     * @return array{
     *     totalCards: int,
     *     uniqueCards: int,
     *     containers: int,
     *     totalPrice: float,
     *     containerTypes: array<string, int>,
     *     rarities: array<string, int>,
     *     topSets: array<int, array{code: string, name: string, count: int}>,
     *     mostValuableCard: array{name: string, price: float, printingsOwned: int}|null,
     *     mostOwnedCard: array{name: string, owned: int, printingsOwned: int}|null,
     * }
     */
    private static function aggregateCollectionStats(?User $scope, ?User $viewer): array
    {
        $userId = $scope?->id;
        $currency = $viewer?->currency
            ?? Locale::from(app()->getLocale())->defaultCurrency();
        $unitPriceSql = ContainerService::unitPriceSql($currency);

        // Single aggregate query for the totals + rarity buckets so
        // the consuming tiles can't disagree with each other if the
        // dataset changes mid-render.
        $totalsQuery = CardStack::query()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id');
        if ($userId !== null) {
            $totalsQuery->where('card_stacks.user_id', $userId);
        }
        $totals = $totalsQuery
            ->selectRaw('COALESCE(SUM(card_stacks.amount), 0) as total_cards')
            ->selectRaw('COUNT(DISTINCT default_cards.id) as unique_cards')
            ->selectRaw("COALESCE(SUM(card_stacks.amount * ({$unitPriceSql})), 0) as total_price")
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as commons', [ScryfallRarity::Common->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as uncommons', [ScryfallRarity::Uncommon->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as rares', [ScryfallRarity::Rare->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN default_cards.rarity = ? THEN card_stacks.amount ELSE 0 END), 0) as mythics', [ScryfallRarity::Mythic->value])
            ->first();

        $containerTypesQuery = Container::query();
        if ($userId !== null) {
            $containerTypesQuery->where('user_id', $userId);
        }
        $containerTypes = $containerTypesQuery
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
            'topSets' => self::topSets($userId),
            'mostValuableCard' => self::mostValuableCard($currency, $userId),
            'mostOwnedCard' => self::mostOwnedCard($userId),
        ];
    }

    /**
     * Top five most-collected sets by total card amount. When
     * `$userId` is null, aggregates across every user's stacks
     * (welcome page); otherwise scopes to that user's collection.
     * Returned in descending order, capped at five, and explicitly
     * *not* run through the collapser — this is a "top N leaderboard"
     * tile, not a distribution, so a bundled "Other" slice would
     * mislead the reader.
     *
     * @return array<int, array{code: string, name: string, count: int}>
     */
    private static function topSets(?string $userId): array
    {
        $query = CardStack::query()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->join('sets', 'default_cards.set_id', '=', 'sets.id');
        if ($userId !== null) {
            $query->where('card_stacks.user_id', $userId);
        }

        return $query
            ->groupBy('sets.id', 'sets.code', 'sets.name')
            ->selectRaw('sets.code as code, sets.name as name, COALESCE(SUM(card_stacks.amount), 0) as cnt')
            // Alphabetical set code tiebreaker so two sets with the
            // same total don't fight for the same slot on each render.
            ->orderByRaw('cnt desc, sets.code asc')
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
     * The most-valuable card *currently owned*, aggregated at the
     * oracle level: every printing of the same oracle card collapses
     * into one entry, with the entry's "price" being the highest
     * unit price across the owned printings. Without this rollup,
     * pricing ties on a single oracle (foil + nonfoil of the same
     * card, multiple Masters-set reprints, etc.) made the displayed
     * row arbitrary and the welcome / collection pages disagreed.
     *
     * Proxies are excluded because their unit price short-circuits to
     * 0. Alphabetical name tiebreaker keeps the result deterministic
     * when multiple oracles share the same top price.
     *
     * Returns null when nobody (in scope) owns a positively-priced
     * card.
     *
     * @return array{name: string, price: float, printingsOwned: int}|null
     */
    private static function mostValuableCard(Currency $currency, ?string $userId): ?array
    {
        $unitPriceSql = ContainerService::unitPriceSql($currency);

        $query = CardStack::query()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->join('oracle_cards', 'default_cards.oracle_id', '=', 'oracle_cards.id')
            ->where('card_stacks.proxy', false);
        if ($userId !== null) {
            $query->where('card_stacks.user_id', $userId);
        }

        $row = $query
            ->groupBy('oracle_cards.id', 'oracle_cards.name')
            ->selectRaw('oracle_cards.name as name')
            ->selectRaw('COUNT(DISTINCT default_cards.id) as printings_owned')
            ->selectRaw("MAX({$unitPriceSql}) as max_unit_price")
            ->orderByRaw("MAX({$unitPriceSql}) desc, oracle_cards.name asc")
            ->limit(1)
            ->first();

        if ($row === null || (float) $row->max_unit_price <= 0) {
            return null;
        }

        return [
            'name' => (string) $row->name,
            'price' => (float) $row->max_unit_price,
            'printingsOwned' => (int) $row->printings_owned,
        ];
    }

    /**
     * The single most-owned card, aggregated at the oracle level:
     * copies are summed across every printing the owner has. Without
     * this rollup, a collection full of four-of constructed staples
     * spread across multiple reprints produced a wide tie on
     * `qty = 4` and the displayed row was arbitrary. Aggregating by
     * oracle collapses "4 × Crop Rotation 7E + 4 × Crop Rotation
     * MM4" into one entry of 8.
     *
     * Basic lands are excluded — without the filter this tile would
     * trivially read "Forest, 14000 copies" for any active scope.
     * Alphabetical name tiebreaker keeps the result deterministic.
     *
     * Returns null when nobody (in scope) owns any non-basic card.
     *
     * @return array{name: string, owned: int, printingsOwned: int}|null
     */
    private static function mostOwnedCard(?string $userId): ?array
    {
        $query = CardStack::query()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->join('oracle_cards', 'default_cards.oracle_id', '=', 'oracle_cards.id')
            ->whereNotIn('oracle_cards.name', FormatProfile::BASIC_LANDS);
        if ($userId !== null) {
            $query->where('card_stacks.user_id', $userId);
        }

        $row = $query
            ->groupBy('oracle_cards.id', 'oracle_cards.name')
            ->selectRaw('oracle_cards.name as name')
            ->selectRaw('COUNT(DISTINCT default_cards.id) as printings_owned')
            ->selectRaw('COALESCE(SUM(card_stacks.amount), 0) as owned')
            ->orderByRaw('COALESCE(SUM(card_stacks.amount), 0) desc, oracle_cards.name asc')
            ->limit(1)
            ->first();

        if ($row === null || (int) $row->owned <= 0) {
            return null;
        }

        return [
            'name' => (string) $row->name,
            'owned' => (int) $row->owned,
            'printingsOwned' => (int) $row->printings_owned,
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
