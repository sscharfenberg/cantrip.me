<?php

namespace App\Http\Controllers\Api;

use App\Enums\Finish;
use App\Http\Controllers\Controller;
use App\Models\OracleCard;
use App\Services\CardSearchParser;
use App\Services\DeckCardSearchService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DefaultCardsController extends Controller
{
    /**
     * Maximum number of printings returned by either search endpoint. The
     * frontend infinite-scrolls in 20-card pages, so 100 covers four pages
     * before the user is expected to refine. Keep both endpoints aligned so
     * the cap is predictable across the UI.
     */
    public const RESULT_LIMIT = 100;

    /**
     * Upper bound on the oracle pre-filter (phase 1). Each oracle has a
     * handful of printings, so this comfortably overshoots the printing-side
     * RESULT_LIMIT while keeping the IN(…) list small enough that MariaDB
     * picks the FK index for phase 2.
     */
    private const ORACLE_PREFILTER_LIMIT = 200;

    /**
     * Build the base printings query, narrowed via a two-phase strategy that
     * mirrors {@see DeckCardSearchService::searchPrintingsForDeck}.
     *
     * Phase 1: when name segments are present, look up matching oracle ids on
     * the smaller oracle_cards table (37k rows) where the indexed
     * searchable_name lives. Returns null when no oracle matches.
     *
     * Phase 2: build a default_cards query against `oracle_id IN (…)` (or no
     * oracle filter when only set:/cn: tokens are used). Set/CN filters are
     * applied here. Returns null when the parser yields nothing usable.
     *
     * @param  array{name_segments: string[], normalized_name_segments: string[], set_code: string|null, collector_number: string|null}  $parsed
     */
    private function buildPrintingsQuery(array $parsed): ?QueryBuilder
    {
        $hasNameSegments = $parsed['normalized_name_segments'] !== [];

        // Phase 1 — only needed when there's a name to match. Pure set:/cn:
        // searches skip this entirely and query default_cards directly via
        // their indexed columns.
        if ($hasNameSegments) {
            $oracleQuery = OracleCard::query();
            foreach ($parsed['normalized_name_segments'] as $segment) {
                $oracleQuery->where('oracle_cards.searchable_name', 'like', "%$segment%");
            }
            $oracleIds = $oracleQuery
                ->limit(self::ORACLE_PREFILTER_LIMIT)
                ->pluck('id');

            if ($oracleIds->isEmpty()) {
                return null;
            }
        }

        $query = DB::table('default_cards');
        if ($hasNameSegments) {
            $query->whereIn('default_cards.oracle_id', $oracleIds);
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

        return $query;
    }

    /**
     * Add the standard exact > prefix > contains ordering on the first
     * normalized name segment, with name-length and alphabetical fallbacks.
     *
     * @param  string[]  $normalizedSegments
     */
    private function applyOrdering(QueryBuilder $query, array $normalizedSegments): void
    {
        $first = $normalizedSegments[0] ?? null;
        if ($first !== null) {
            $query->orderByRaw(
                'CASE
                    WHEN default_cards.searchable_name = ? THEN 0
                    WHEN default_cards.searchable_name LIKE ? THEN 1
                    ELSE 2
                END',
                [$first, $first.'%']
            );
        }
        $query->orderBy('default_cards.name');
    }

    /**
     * Search default_cards by name (and optionally set code) and return id +
     * art crop. Used by the container hero-image picker.
     *
     * Supports "set:xxx" and "number:xxx" tokens in the query string, e.g.:
     *   "sol ring set:lea"  →  name LIKE %sol% AND name LIKE %ring% AND set.code = 'lea'
     *   "set:lea"           →  all cards from set 'lea'
     *   "number:123"        →  collector_number = '123'
     */
    public function artCropSearch(Request $request): JsonResponse
    {
        $parsed = CardSearchParser::parse(trim($request->query('q', '')));
        if (! $parsed) {
            return response()->json(['total' => 0, 'results' => []]);
        }

        $base = $this->buildPrintingsQuery($parsed);
        if ($base === null) {
            return response()->json(['total' => 0, 'results' => []]);
        }
        $base->whereNotNull('default_cards.art_crop');

        // Total comes from the same filtered base before ORDER BY/LIMIT —
        // cheap because phase 1 already narrowed to <= ORACLE_PREFILTER_LIMIT
        // oracles (or to a set/cn-bounded slice) so the printings count is a
        // small indexed lookup.
        $total = (clone $base)->count();

        $rows = $this->applyOrderingAndFetch($base, $parsed['normalized_name_segments'], [
            'default_cards.id',
            'default_cards.name AS card_name',
            'default_cards.art_crop',
            'sets.name AS set_name',
            'sets.code AS set_code',
            'sets.path AS set_path',
            'artists.name AS artist_name',
        ]);

        $results = $rows->map(fn (object $row): array => [
            'id' => $row->id,
            'name' => $row->card_name,
            'art_crop' => $row->art_crop,
            'artist' => $row->artist_name,
            'set' => $row->set_code !== null ? [
                'name' => $row->set_name,
                'code' => $row->set_code,
                'path' => $row->set_path,
            ] : null,
        ])->values();

        return response()->json(['total' => $total, 'results' => $results]);
    }

    /**
     * Search default_cards by name (and optionally set code) and return card
     * face images. Used by the card-stack add flow.
     */
    public function searchCardImage(Request $request): JsonResponse
    {
        $parsed = CardSearchParser::parse(trim($request->query('q', '')));
        if (! $parsed) {
            return response()->json(['total' => 0, 'results' => []]);
        }

        $base = $this->buildPrintingsQuery($parsed);
        if ($base === null) {
            return response()->json(['total' => 0, 'results' => []]);
        }

        $total = (clone $base)->count();

        $rows = $this->applyOrderingAndFetch($base, $parsed['normalized_name_segments'], [
            'default_cards.id',
            'default_cards.name AS card_name',
            'default_cards.card_image_0',
            'default_cards.card_image_1',
            'default_cards.collector_number',
            'default_cards.finishes',
            'sets.name AS set_name',
            'sets.code AS set_code',
            'sets.path AS set_path',
            'artists.name AS artist_name',
        ]);

        $results = $rows->map(fn (object $row): array => [
            'id' => $row->id,
            'name' => $row->card_name,
            'card_image_0' => $row->card_image_0,
            'card_image_1' => $row->card_image_1,
            'artist' => $row->artist_name,
            'cn' => $row->collector_number,
            'finishes' => Finish::labelsFromMask((int) $row->finishes),
            'set' => $row->set_code !== null ? [
                'name' => $row->set_name,
                'code' => $row->set_code,
                'path' => $row->set_path,
            ] : null,
        ])->values();

        return response()->json(['total' => $total, 'results' => $results]);
    }

    /**
     * Apply joins, ordering and the limit, then fetch the result rows.
     *
     * Both endpoints want the same joined shape (sets + artists, both LEFT
     * joins) so consolidating the fetch keeps the per-endpoint methods
     * focused on the response mapping.
     *
     * @param  string[]  $normalizedSegments
     * @param  string[]  $columns
     */
    private function applyOrderingAndFetch(QueryBuilder $base, array $normalizedSegments, array $columns): Collection
    {
        $base
            ->leftJoin('sets', 'sets.id', '=', 'default_cards.set_id')
            ->leftJoin('artists', 'artists.id', '=', 'default_cards.artist_id');
        $this->applyOrdering($base, $normalizedSegments);

        return $base->limit(self::RESULT_LIMIT)->get($columns);
    }
}
