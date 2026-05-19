<?php

namespace App\Services;

use App\Models\OracleCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds printing-level (`default_cards`) search queries from a parsed
 * {@see CardSearchParser} payload. Used by deck-less search endpoints —
 * the container hero-image picker and the card-stack add flow — where the
 * deck-aware machinery in {@see DeckCardSearchService::searchPrintingsForDeck}
 * doesn't apply.
 *
 * Two-phase strategy:
 *
 * Phase 1: when name segments are present, look up matching oracle ids on
 * the smaller `oracle_cards` table (~37k rows) via the indexed
 * `searchable_name` column. `set:` / `cn:` tokens are pushed down into this
 * phase so the {@see ORACLE_PREFILTER_LIMIT} slice contains only oracles
 * that actually have a matching printing — otherwise a query like
 * `set:ice ill` would cap the alphabetical phase-1 scan before reaching
 * "Illusions of Grandeur", and phase 2 would never see it.
 *
 * Phase 2: a `default_cards` query against `oracle_id IN (…)` (or no
 * oracle filter when only `set:` / `cn:` tokens are used). Set/CN filters
 * are re-applied here to narrow printings down to the requested
 * set/number even when an oracle has multiple printings.
 *
 * Mirrors the pattern in {@see DeckCardSearchService::searchPrintingsForDeck}
 * — kept as a separate service because that one layers deck-aware filters
 * (format legality, color identity, companion violation) on top.
 */
final class DefaultCardSearchService
{
    /**
     * Maximum number of printings returned by the search endpoints. The
     * frontend infinite-scrolls in 20-card pages, so 100 covers four pages
     * before the user is expected to refine.
     */
    public const RESULT_LIMIT = 100;

    /**
     * Upper bound on the oracle pre-filter (phase 1). Each oracle has a
     * handful of printings, so this comfortably overshoots the printing-side
     * RESULT_LIMIT while keeping the IN(…) list small enough that MariaDB
     * picks the FK index for phase 2.
     */
    public const ORACLE_PREFILTER_LIMIT = 200;

    /**
     * Build the base printings query for a parsed search payload.
     *
     * Returns null when the parser yields nothing usable (e.g. a pure
     * name-only search whose phase-1 prefilter matched zero oracles, or a
     * payload with no filters at all).
     *
     * The returned `oracle_searchable_names` map (oracle_card_id =>
     * searchable_name) feeds {@see OracleNameSearch::resolveMatchedTranslations}
     * so the controller can annotate result rows with their printed
     * foreign-language name when the English oracle didn't match the
     * segments. The map is empty on pure set:/cn: payloads — phase 1
     * is skipped there and there's no foreign-language match to explain.
     *
     * @param  array{name_segments: string[], normalized_name_segments: string[], set_code: string|null, collector_number: string|null}  $parsed
     * @return array{query: QueryBuilder, oracle_searchable_names: array<string, string>}|null
     */
    public static function buildQuery(array $parsed): ?array
    {
        $hasNameSegments = $parsed['normalized_name_segments'] !== [];
        /** @var array<string, string> $oracleSearchableNames */
        $oracleSearchableNames = [];

        // Phase 1 — only needed when there's a name to match. Pure set:/cn:
        // searches skip this entirely and query default_cards directly via
        // their indexed columns.
        if ($hasNameSegments) {
            $oracleQuery = OracleCard::query();
            OracleNameSearch::applyMultiTableNameSegments($oracleQuery, $parsed['normalized_name_segments']);
            if ($parsed['set_code']) {
                $oracleQuery->whereHas('defaults', fn (Builder $q) => $q->whereHas(
                    'set',
                    fn (Builder $sq) => $sq->where('code', $parsed['set_code'])
                ));
            }
            if ($parsed['collector_number']) {
                $oracleQuery->whereHas(
                    'defaults',
                    fn (Builder $q) => $q->where('collector_number', $parsed['collector_number'])
                );
            }
            // Rank phase 1 deterministically — exact > prefix > contains on
            // the first segment — so the LIMIT slice surfaces the most likely
            // hits if the cap still bites.
            $first = $parsed['normalized_name_segments'][0];
            $oracleQuery->orderByRaw(
                'CASE
                    WHEN oracle_cards.searchable_name = ? THEN 0
                    WHEN oracle_cards.searchable_name LIKE ? THEN 1
                    ELSE 2
                END',
                [$first, $first.'%']
            );
            $oracleRows = $oracleQuery
                ->limit(self::ORACLE_PREFILTER_LIMIT)
                ->get(['oracle_cards.id', 'oracle_cards.searchable_name']);

            if ($oracleRows->isEmpty()) {
                return null;
            }
            foreach ($oracleRows as $row) {
                $oracleSearchableNames[(string) $row->id] = (string) $row->searchable_name;
            }
        }

        $query = DB::table('default_cards');
        if ($hasNameSegments) {
            $query->whereIn('default_cards.oracle_id', array_keys($oracleSearchableNames));
        }

        if ($parsed['set_code']) {
            $query->whereExists(function ($sub) use ($parsed): void {
                $sub->select(DB::raw(1))
                    ->from('sets')
                    ->whereColumn('sets.id', 'default_cards.set_id')
                    ->where('sets.code', $parsed['set_code']);
            });
        }

        if ($parsed['collector_number']) {
            $query->where('default_cards.collector_number', $parsed['collector_number']);
        }

        // For pure set:/cn: searches with no name segments, the parser still
        // succeeds — but without any other narrowing the query would scan
        // the full table. Bail out so the endpoint stays predictable.
        if (! $hasNameSegments && ! $parsed['set_code'] && ! $parsed['collector_number']) {
            return null;
        }

        return ['query' => $query, 'oracle_searchable_names' => $oracleSearchableNames];
    }

    /**
     * Apply joins (sets + artists), the exact > prefix > contains ordering,
     * the result limit, and fetch the requested columns.
     *
     * Both controller endpoints want the same joined shape, so consolidating
     * the fetch keeps the per-endpoint methods focused on response mapping.
     *
     * @param  string[]  $normalizedSegments
     * @param  string[]  $columns
     */
    public static function orderAndFetch(QueryBuilder $base, array $normalizedSegments, array $columns): Collection
    {
        $base
            ->leftJoin('sets', 'sets.id', '=', 'default_cards.set_id')
            ->leftJoin('artists', 'artists.id', '=', 'default_cards.artist_id');

        $first = $normalizedSegments[0] ?? null;
        if ($first !== null) {
            $base->orderByRaw(
                'CASE
                    WHEN default_cards.searchable_name = ? THEN 0
                    WHEN default_cards.searchable_name LIKE ? THEN 1
                    ELSE 2
                END',
                [$first, $first.'%']
            );
        }
        $base->orderBy('default_cards.name');

        return $base->limit(self::RESULT_LIMIT)->get($columns);
    }
}
