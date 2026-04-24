<?php

namespace App\Services;

use App\Enums\Finish;
use App\Models\Deck;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Searches for cards eligible to be added to a deck.
 *
 * Two public entry points, same response shape, different use cases:
 *
 *  - {@see searchOracleForDeck} — oracle-level search. Ignores any
 *    `set:` / `cn:` tokens and returns distinct oracle cards with their
 *    newest printing auto-resolved. Used by the quick-add input so the
 *    user types a name and picks an oracle card without worrying about
 *    which printing to show.
 *  - {@see searchPrintingsForDeck} — printing-level search. Honors
 *    `set:` / `cn:` tokens and can return multiple printings of the
 *    same oracle card. Used by the full card-add modal where the user
 *    explicitly picks a specific printing. An optional `includeNonLegal`
 *    flag drops the format-legality filter while keeping color identity
 *    enforcement — for when the user wants to sleeve up something banned
 *    in a Rule 0 / kitchen table context.
 *
 * Both paths:
 *  - Filter by commander color identity when the format enforces it
 *  - AND-match every normalized query segment against `searchable_name`
 *  - Rank exact > prefix > contains on the first segment
 *
 * Legality is applied unconditionally in the oracle path and conditionally
 * in the printings path.
 */
final class DeckCardSearchService
{
    public const DEFAULT_LIMIT = 20;

    public const PRINTINGS_LIMIT = 60;

    /**
     * Oracle-level search — returns up to $limit distinct oracle cards,
     * each with its newest printing resolved.
     *
     * Any `set:` / `cn:` tokens in the query are ignored; this path is
     * about picking a card by name, not a specific printing.
     *
     * The newest printing is fetched in a single batched query rather than
     * via an Eloquent `hasOneOfMany` relationship, because Laravel's
     * `ofMany` builder can't express "aggregate on a joined table column"
     * without generating an invalid dotted SQL alias.
     *
     * @return array<int, array{
     *     oracle_id: string,
     *     name: string,
     *     cmc: float,
     *     color_identity: string|null,
     *     printing: array{
     *         id: string,
     *         card_image_0: string|null,
     *         card_image_1: string|null,
     *         set_code: string,
     *         collector_number: string
     *     }|null
     * }>
     */
    public static function searchOracleForDeck(Deck $deck, string $rawQuery, int $limit = self::DEFAULT_LIMIT): array
    {
        $parsed = CardSearchParser::parse($rawQuery);
        if (! $parsed) {
            return [];
        }

        $query = OracleCard::query()->legalIn($deck->format);

        self::applyColorIdentityFilter($query, $deck);
        self::applyNameSegments($query, 'oracle_cards.searchable_name', $parsed['normalized_name_segments']);
        self::applyNameRanking($query, 'oracle_cards.searchable_name', 'oracle_cards.name', $parsed['normalized_name_segments']);

        $oracleCards = $query
            ->select('oracle_cards.id', 'oracle_cards.name', 'oracle_cards.cmc', 'oracle_cards.color_identity', 'oracle_cards.searchable_name')
            ->limit($limit)
            ->get();

        if ($oracleCards->isEmpty()) {
            return [];
        }

        $newestPrintings = self::fetchNewestPrintings($oracleCards->pluck('id')->all());

        return $oracleCards
            ->map(fn (OracleCard $card): array => [
                'oracle_id' => $card->id,
                'name' => $card->name,
                'cmc' => (float) $card->cmc,
                'color_identity' => $card->color_identity,
                'printing' => $newestPrintings[$card->id] ?? null,
            ])
            ->all();
    }

    /**
     * Batch-fetch the newest printing for each given oracle card id.
     *
     * "Newest" is decided by `sets.released_at` (desc), with `default_cards.id`
     * as a deterministic tie-breaker. Returns a map keyed by oracle_id so the
     * caller can look up each printing in O(1) when assembling results.
     *
     * @param  string[]  $oracleIds
     * @return array<string, array{id: string, card_image_0: string|null, card_image_1: string|null, set_code: string, collector_number: string}>
     */
    private static function fetchNewestPrintings(array $oracleIds): array
    {
        if ($oracleIds === []) {
            return [];
        }

        $rows = DB::table('default_cards as dc')
            ->join('sets as s', 'dc.set_id', '=', 's.id')
            ->whereIn('dc.oracle_id', $oracleIds)
            ->select(
                'dc.id',
                'dc.oracle_id',
                'dc.card_image_0',
                'dc.card_image_1',
                'dc.collector_number',
                's.code as set_code',
                's.released_at',
            )
            ->orderBy('s.released_at', 'desc')
            ->orderBy('dc.id', 'desc')
            ->get();

        $printings = [];
        foreach ($rows as $row) {
            if (! isset($printings[$row->oracle_id])) {
                $printings[$row->oracle_id] = [
                    'id' => $row->id,
                    'card_image_0' => $row->card_image_0,
                    'card_image_1' => $row->card_image_1,
                    'set_code' => $row->set_code,
                    'collector_number' => $row->collector_number,
                ];
            }
        }

        return $printings;
    }

    /**
     * Printing-level search — returns all matching printings.
     * Filters push into the related oracle card so legality + CI still apply.
     *
     * Honors `set:` / `cn:` tokens from the query so the user can pin
     * results to a specific printing. When `$includeNonLegal` is true,
     * the format-legality filter is dropped but color identity is still
     * enforced — this is the escape hatch for Rule 0 / kitchen table play.
     *
     * @return array<int, array{
     *     oracle_id: string,
     *     name: string,
     *     cmc: float,
     *     color_identity: string|null,
     *     printing: array{
     *         id: string,
     *         name: string,
     *         card_image_0: string|null,
     *         card_image_1: string|null,
     *         artist: string|null,
     *         cn: string,
     *         finishes: array<string>,
     *         set: array{name: string, code: string, path: string|null}|null
     *     }
     * }>
     */
    public static function searchPrintingsForDeck(Deck $deck, string $rawQuery, bool $includeNonLegal = false): array
    {
        $parsed = CardSearchParser::parse($rawQuery);
        if (! $parsed) {
            return [];
        }

        $query = DefaultCard::query()
            ->whereHas('oracle', function (Builder $q) use ($deck, $includeNonLegal): void {
                if (! $includeNonLegal) {
                    $q->legalIn($deck->format);
                }
                self::applyColorIdentityFilter($q, $deck);
            })
            ->with(['set:id,name,code,path', 'oracle:id,name,cmc,color_identity', 'artist:id,name']);

        if ($parsed['set_code']) {
            $query->whereHas('set', fn (Builder $q) => $q->where('code', $parsed['set_code']));
        }

        if ($parsed['collector_number']) {
            $query->where('collector_number', $parsed['collector_number']);
        }

        self::applyNameSegments($query, 'default_cards.searchable_name', $parsed['normalized_name_segments']);
        self::applyNameRanking($query, 'default_cards.searchable_name', 'default_cards.name', $parsed['normalized_name_segments']);

        return $query
            ->select('default_cards.id', 'default_cards.oracle_id', 'default_cards.name', 'default_cards.card_image_0', 'default_cards.card_image_1', 'default_cards.collector_number', 'default_cards.finishes', 'default_cards.artist_id', 'default_cards.set_id', 'default_cards.searchable_name')
            ->limit(self::PRINTINGS_LIMIT)
            ->get()
            ->map(fn (DefaultCard $card): array => [
                'oracle_id' => $card->oracle_id,
                'name' => $card->oracle?->name ?? $card->name,
                'cmc' => (float) ($card->oracle?->cmc ?? 0),
                'color_identity' => $card->oracle?->color_identity,
                'printing' => [
                    'id' => $card->id,
                    'name' => $card->name,
                    'card_image_0' => $card->card_image_0,
                    'card_image_1' => $card->card_image_1,
                    'artist' => $card->artist?->name,
                    'cn' => $card->collector_number,
                    'finishes' => Finish::labelsFromMask($card->finishes),
                    'set' => $card->set ? [
                        'name' => $card->set->name,
                        'code' => $card->set->code,
                        'path' => $card->set->path,
                    ] : null,
                ],
            ])
            ->all();
    }

    /**
     * Constrain the query to cards inside the deck's color identity.
     *
     * Only applies when the format enforces color identity (Commander,
     * Oathbreaker, Brawl, etc.). `$deck->colors` holds the identity for
     * commander formats. Empty `color_identity` (colorless) is always
     * allowed — the regex `^[WUBRG]*$` matches the empty string.
     *
     * @param  Builder<OracleCard>  $query
     */
    private static function applyColorIdentityFilter(Builder $query, Deck $deck): void
    {
        $profile = $deck->format->rules();
        if (! $profile->enforcesColorIdentity()) {
            return;
        }

        $colors = $deck->colors ?? '';
        // Whitelist to WUBRG to defeat any injection; `colors` is already
        // enum-like in the app but this is the regex character class.
        $safeColors = preg_replace('/[^WUBRG]/', '', $colors) ?? '';

        $query->where(function (Builder $q) use ($safeColors): void {
            $q->whereNull('color_identity')
                ->orWhere('color_identity', '');

            // Only add the REGEXP branch when the deck actually has colors.
            // For colorless decks the NULL / empty checks above are enough,
            // and an empty character class (`[]`) is invalid regex anyway.
            if ($safeColors !== '') {
                $q->orWhereRaw('color_identity REGEXP ?', ['^['.$safeColors.']*$']);
            }
        });
    }

    /**
     * AND-match each normalized segment against the given column.
     *
     * @param  Builder<OracleCard|DefaultCard>  $query
     * @param  string[]  $segments
     */
    private static function applyNameSegments(Builder $query, string $column, array $segments): void
    {
        foreach ($segments as $segment) {
            $query->where($column, 'like', "%{$segment}%");
        }
    }

    /**
     * Order by exact/prefix/contains rank on the first segment, then by
     * name length (shortest wins), then alphabetical.
     *
     * @param  Builder<OracleCard|DefaultCard>  $query
     * @param  string[]  $segments
     */
    private static function applyNameRanking(Builder $query, string $searchableColumn, string $nameColumn, array $segments): void
    {
        $first = $segments[0] ?? null;
        if ($first !== null) {
            $query->orderByRaw(
                "CASE
                    WHEN {$searchableColumn} = ? THEN 0
                    WHEN {$searchableColumn} LIKE ? THEN 1
                    ELSE 2
                END",
                [$first, $first.'%']
            );
        }

        $query->orderByRaw("CHAR_LENGTH({$nameColumn})")->orderBy($nameColumn);
    }
}
