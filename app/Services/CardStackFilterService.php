<?php

namespace App\Services;

use App\Enums\CardCondition;
use App\Enums\CardLanguage;
use App\Enums\Currency;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Filters for the two card-stack data tables: the collection page and the
 * container page.
 *
 * Filters travel as plain query parameters next to the table's own `sort`,
 * `page` and `search`, so the DataTable carries them across every navigation
 * without knowing what they are. {@see fromRequest()} reads them as forgivingly
 * as {@see DataTableService} reads its own parameters: an invalid value is
 * dropped, never answered with a 422, so a hand-edited or stale bookmark still
 * renders a table.
 *
 * What it returns is exactly what {@see apply()} filters by and what the table
 * response echoes back, so the UI only ever shows filters the server applied.
 */
class CardStackFilterService
{
    public const PROXY_ONLY = 'only';

    public const PROXY_NONE = 'none';

    public const CLAIMED_ANY = 'any';

    public const CLAIMED_NONE = 'none';

    public const CONTAINER_UNSORTED = 'unsorted';

    public const COLORLESS = 'C';

    public const COLOR_MODE_INCLUDE = 'include';

    public const COLOR_MODE_EXACT = 'exact';

    public const COLOR_MODE_WITHIN = 'within';

    /** Card types the `type` filter offers, matched against `oracle_cards.type_line`. */
    public const CARD_TYPES = ['Creature', 'Planeswalker', 'Battle', 'Artifact', 'Enchantment', 'Instant', 'Sorcery', 'Land'];

    /** WUBRG order — a normalized `colors` value is always written in it. */
    private const COLORS = ['W', 'U', 'B', 'R', 'G'];

    /** Display order of the rarity dropdown; Scryfall's own enum puts `special` before `mythic`. */
    private const RARITIES = ['common', 'uncommon', 'rare', 'mythic', 'special', 'bonus'];

    /** Highest price a filter accepts. Anything above it is a typo, not a filter. */
    private const MAX_PRICE = 1_000_000;

    /**
     * Read the filter query parameters, dropping every value that is not valid.
     *
     * @param  bool  $withContainer  Accept the `container` filter (the collection page; a container page is one container already).
     * @param  bool  $withClaims  Accept the `claimed` filter. Owner-only: claims name the owner's decks.
     * @return array<string, string>
     */
    public static function fromRequest(Request $request, bool $withContainer, bool $withClaims): array
    {
        $filters = [];

        foreach (['min_price', 'max_price'] as $key) {
            $price = self::price(self::param($request, $key));
            if ($price !== null) {
                $filters[$key] = $price;
            }
        }

        $proxy = self::param($request, 'proxy');
        if (in_array($proxy, [self::PROXY_ONLY, self::PROXY_NONE], true)) {
            $filters['proxy'] = $proxy;
        }

        $language = CardLanguage::tryFrom((string) self::param($request, 'lang'));
        if ($language !== null) {
            $filters['lang'] = $language->value;
        }

        $condition = CardCondition::tryFrom((string) self::param($request, 'condition'));
        if ($condition !== null) {
            $filters['condition'] = $condition->value;
        }

        $finish = Finish::fromLabel((string) self::param($request, 'finish'));
        if ($finish !== null) {
            $filters['finish'] = $finish->label();
        }

        $rarity = self::param($request, 'rarity');
        if (in_array($rarity, self::RARITIES, true)) {
            $filters['rarity'] = $rarity;
        }

        $set = Str::lower((string) self::param($request, 'set'));
        if (preg_match('/^[a-z0-9]{2,8}$/', $set) === 1) {
            $filters['set'] = $set;
        }

        $type = self::param($request, 'type');
        if (in_array($type, self::CARD_TYPES, true)) {
            $filters['type'] = $type;
        }

        $colors = self::colors(self::param($request, 'colors'));
        if ($colors !== null) {
            $filters['colors'] = $colors;

            $mode = self::param($request, 'color_mode');
            if ($colors !== self::COLORLESS && in_array($mode, [self::COLOR_MODE_EXACT, self::COLOR_MODE_WITHIN], true)) {
                $filters['color_mode'] = $mode;
            }
        }

        if (self::param($request, 'reserved') === '1') {
            $filters['reserved'] = '1';
        }

        if ($withContainer) {
            $container = (string) self::param($request, 'container');
            if ($container === self::CONTAINER_UNSORTED || Str::isUuid($container)) {
                $filters['container'] = $container;
            }
        }

        if ($withClaims) {
            $claimed = (string) self::param($request, 'claimed');
            if (in_array($claimed, [self::CLAIMED_ANY, self::CLAIMED_NONE], true) || Str::isUuid($claimed)) {
                $filters['claimed'] = $claimed;
            }
        }

        return $filters;
    }

    /**
     * Narrow a card-stack table query to the given filters.
     *
     * Prices compare the same per-copy price the table shows — the finish's
     * price column in the viewer's currency, a proxy at 0 — so the expression
     * is repeated here rather than referenced by its `unit_price` alias: an
     * alias is not allowed in WHERE, and HAVING would break the paginator's
     * count query.
     *
     * @param  Builder<CardStack>|HasMany<CardStack, Container>  $query  Already joined onto `default_cards` and `sets`.
     * @param  array<string, string>  $filters  As returned by {@see fromRequest()}.
     */
    public static function apply(Builder|HasMany $query, array $filters, Currency $currency): void
    {
        $unitPrice = 'COALESCE('.ContainerService::unitPriceSql($currency).', 0)';

        // PDO binds the bound as a string, and SQLite compares a CASE result
        // (which has no type affinity) with a string by storage class — every
        // number sorts below every string. The CAST makes it a number on
        // both engines.
        if (isset($filters['min_price'])) {
            $query->whereRaw("{$unitPrice} >= CAST(? AS DECIMAL(12, 2))", [$filters['min_price']]);
        }

        if (isset($filters['max_price'])) {
            $query->whereRaw("{$unitPrice} <= CAST(? AS DECIMAL(12, 2))", [$filters['max_price']]);
        }

        if (isset($filters['proxy'])) {
            $query->where('card_stacks.proxy', $filters['proxy'] === self::PROXY_ONLY);
        }

        if (isset($filters['lang'])) {
            $query->where('card_stacks.language', $filters['lang']);
        }

        if (isset($filters['condition'])) {
            $query->where('card_stacks.condition', $filters['condition']);
        }

        if (isset($filters['finish'])) {
            $query->where('card_stacks.finish', Finish::fromLabel($filters['finish'])->value);
        }

        if (isset($filters['rarity'])) {
            $query->where('default_cards.rarity', $filters['rarity']);
        }

        if (isset($filters['set'])) {
            $query->where('sets.code', $filters['set']);
        }

        if (isset($filters['type']) || isset($filters['colors']) || isset($filters['reserved'])) {
            $query->whereIn('default_cards.oracle_id', function (QueryBuilder $oracle) use ($filters) {
                $oracle->select('oracle_cards.id')->from('oracle_cards');

                if (isset($filters['type'])) {
                    $oracle->where('oracle_cards.type_line', 'like', "%{$filters['type']}%");
                }

                if (isset($filters['colors'])) {
                    self::applyColors($oracle, $filters['colors'], $filters['color_mode'] ?? self::COLOR_MODE_INCLUDE);
                }

                if (isset($filters['reserved'])) {
                    $oracle->where('oracle_cards.reserved', true);
                }
            });
        }

        if (isset($filters['container'])) {
            if ($filters['container'] === self::CONTAINER_UNSORTED) {
                $query->whereNull('card_stacks.container_id');
            } else {
                $query->where('card_stacks.container_id', $filters['container']);
            }
        }

        if (isset($filters['claimed'])) {
            self::applyClaimed($query, $filters['claimed']);
        }
    }

    /**
     * Choices for the filter dropdowns.
     *
     * Read from the table's rows before search and filters, so a choice never
     * disappears because another filter is active. Languages, sets and
     * rarities list only what the rows actually hold; the decks are the ones
     * explicitly claiming at least one of the rows.
     *
     * @param  Builder<CardStack>  $scope  The table's unfiltered rows: a user's stacks, or a container's.
     * @param  User|null  $containersOf  Offer this user's containers (the collection page); null on a container page.
     * @param  bool  $withClaims  Offer the decks for the `claimed` filter. Owner-only.
     * @return array{
     *     languages: array<int, string>,
     *     conditions: array<int, string>,
     *     finishes: array<int, string>,
     *     rarities: array<int, string>,
     *     types: array<int, string>,
     *     sets: array<int, array{code: string, name: string, path: string|null}>,
     *     containers?: array<int, array{id: string, name: string}>,
     *     decks?: array<int, array{id: string, name: string}>,
     * }
     */
    public static function options(Builder $scope, ?User $containersOf, bool $withClaims): array
    {
        $printings = (clone $scope)->toBase()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id');

        $options = [
            'languages' => (clone $scope)->toBase()
                ->distinct()
                ->orderBy('card_stacks.language')
                ->pluck('card_stacks.language')
                ->all(),
            'conditions' => array_map(fn (CardCondition $condition) => $condition->value, CardCondition::cases()),
            'finishes' => Finish::labels(),
            'rarities' => array_values(array_intersect(
                self::RARITIES,
                (clone $printings)->distinct()->pluck('default_cards.rarity')->all(),
            )),
            'types' => self::CARD_TYPES,
            'sets' => (clone $printings)
                ->join('sets', 'default_cards.set_id', '=', 'sets.id')
                ->distinct()
                ->orderBy('sets.name')
                ->get(['sets.code', 'sets.name', 'sets.path'])
                ->map(fn (object $set) => ['code' => $set->code, 'name' => $set->name, 'path' => $set->path])
                ->all(),
        ];

        if ($containersOf !== null) {
            $options['containers'] = Container::query()
                ->where('user_id', $containersOf->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Container $container) => ['id' => $container->id, 'name' => $container->name])
                ->all();
        }

        if ($withClaims) {
            $options['decks'] = Deck::query()
                ->whereIn('id', DB::table('deck_cards')
                    ->join('deck_card_card_stack', 'deck_card_card_stack.deck_card_id', '=', 'deck_cards.id')
                    ->whereIn('deck_card_card_stack.card_stack_id', (clone $scope)->select('card_stacks.id'))
                    ->select('deck_cards.deck_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Deck $deck) => ['id' => $deck->id, 'name' => $deck->name])
                ->all();
        }

        return $options;
    }

    /**
     * A query parameter as a non-empty string, or null. Arrays (`?lang[]=de`)
     * count as absent rather than being coerced.
     */
    private static function param(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Normalize a price bound to a plain decimal string ("1,50" → "1.5"), or
     * null when it is not a non-negative number within range.
     */
    private static function price(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace(',', '.', $value);
        if (! is_numeric($value) || str_starts_with(ltrim($value), '-')) {
            return null;
        }

        $price = round((float) $value, 2);

        return $price <= self::MAX_PRICE ? (string) $price : null;
    }

    /**
     * Normalize a color selection to WUBRG order ("gw" → "WG"), or "C" for
     * colorless. Unknown letters are dropped; colorless mixed with colors
     * means the colors.
     */
    private static function colors(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $letters = str_split(Str::upper($value));
        if ($letters === [self::COLORLESS]) {
            return self::COLORLESS;
        }

        $colors = implode('', array_intersect(self::COLORS, $letters));

        return $colors === '' ? null : $colors;
    }

    /**
     * Color identity against the selected colors:
     *  - include — has every selected color (and maybe more);
     *  - exact   — has every selected color and no other;
     *  - within  — has no color outside the selection, so it fits a deck of
     *              those colors. Colorless cards fit every deck.
     *
     * A colorless card stores NULL, so every "does not have" check lets NULL
     * through explicitly — `NULL NOT LIKE …` is NULL, not true.
     */
    private static function applyColors(QueryBuilder $oracle, string $colors, string $mode): void
    {
        if ($colors === self::COLORLESS) {
            $oracle->where(fn (QueryBuilder $q) => $q
                ->whereNull('oracle_cards.color_identity')
                ->orWhere('oracle_cards.color_identity', ''));

            return;
        }

        $selected = str_split($colors);

        if ($mode !== self::COLOR_MODE_WITHIN) {
            foreach ($selected as $color) {
                $oracle->where('oracle_cards.color_identity', 'like', "%{$color}%");
            }
        }

        if ($mode !== self::COLOR_MODE_INCLUDE) {
            foreach (array_diff(self::COLORS, $selected) as $color) {
                $oracle->where(fn (QueryBuilder $q) => $q
                    ->whereNull('oracle_cards.color_identity')
                    ->orWhere('oracle_cards.color_identity', 'not like', "%{$color}%"));
            }
        }
    }

    /**
     * Explicit claims only — a `deck_card_card_stack` pivot row, the same
     * thing the table's "Claimed" column shows. A stack merely sitting in a
     * deck's deckbox (mode B) is not claimed here, or the filter would return
     * rows whose claim badge is empty.
     *
     * @param  Builder<CardStack>|HasMany<CardStack, Container>  $query
     */
    private static function applyClaimed(Builder|HasMany $query, string $claimed): void
    {
        $claims = fn (QueryBuilder $pivot) => $pivot
            ->select(DB::raw(1))
            ->from('deck_card_card_stack')
            ->whereColumn('deck_card_card_stack.card_stack_id', 'card_stacks.id');

        if ($claimed === self::CLAIMED_ANY) {
            $query->whereExists($claims);
        } elseif ($claimed === self::CLAIMED_NONE) {
            $query->whereNotExists($claims);
        } else {
            $query->whereExists(fn (QueryBuilder $pivot) => $claims($pivot)
                ->join('deck_cards', 'deck_cards.id', '=', 'deck_card_card_stack.deck_card_id')
                ->where('deck_cards.deck_id', $claimed));
        }
    }
}
