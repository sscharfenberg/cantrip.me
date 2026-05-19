<?php

namespace App\Services;

use App\Models\OracleCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Applies the AND-matched, multi-segment name filter that the three
 * card-search services share, extended to OR against
 * `oracle_card_translations` + `oracle_card_face_translations` so
 * users can find a card by any printed-language name (e.g. typing
 * "Blitzschlag" returns Lightning Bolt).
 *
 * Shape of the produced WHERE clause for a single segment "$s":
 *
 *     AND (
 *          oracle_cards.searchable_name LIKE '%$s%'
 *       OR EXISTS (SELECT 1 FROM oracle_card_translations oct
 *                  WHERE oct.oracle_card_id = oracle_cards.id
 *                    AND oct.searchable_name LIKE '%$s%')
 *       OR EXISTS (SELECT 1 FROM oracle_card_face_translations ofct
 *                  WHERE ofct.oracle_card_id = oracle_cards.id
 *                    AND ofct.searchable_name LIKE '%$s%')
 *     )
 *
 * Repeated per segment so multi-word queries still AND across
 * segments — each segment can independently match the English name
 * or any translation; the outer AND across segments preserves the
 * existing semantics for plain English search.
 *
 * **Why no `lang` filter:** v1 ships unfiltered across all 17
 * non-English languages. The hot-path index on
 * `(lang, searchable_name)` is therefore not used by these queries
 * — the optimizer falls back to the FK `oracle_card_id` (the
 * leading column of the composite PK) for the join. If EXPLAIN
 * shows pain after deploy, the fix is a `WHERE oct.lang IN
 * (user.locale, 'en')` slice — see docs/foreign-language-search.md.
 *
 * **Why ranking is unchanged:** the existing `applyNameRanking`
 * helpers on each service rank by `oracle_cards.searchable_name`
 * only. A foreign-only match has nothing to rank against and falls
 * through to the alphabetical tiebreaker. Acceptable for distinctive
 * names ("Blitzschlag" is uniquely Lightning Bolt) but degrades on
 * short ambiguous tokens.
 */
final class OracleNameSearch
{
    public const ORACLE_TRANSLATION_TABLE = 'oracle_card_translations';

    public const FACE_TRANSLATION_TABLE = 'oracle_card_face_translations';

    /**
     * Apply the multi-segment name filter onto an OracleCard query,
     * OR-ed against the two translation tables for each segment.
     *
     * The query must be rooted at `oracle_cards` (Eloquent `OracleCard`
     * or `DB::table('oracle_cards')`) — the EXISTS subqueries
     * reference `oracle_cards.id` directly without any alias
     * resolution.
     *
     * Accepts either an Eloquent or a Query Builder — both expose a
     * compatible `where(callable)` API, and the only difference at
     * the SQL layer is hydration, which we don't engage here.
     *
     * @param  Builder<OracleCard>|QueryBuilder  $query
     * @param  string[]  $segments  Normalized search segments (from {@see CardNameNormalizer::normalize}).
     */
    public static function applyMultiTableNameSegments(Builder|QueryBuilder $query, array $segments): void
    {
        foreach ($segments as $segment) {
            $query->where(function (Builder|QueryBuilder $q) use ($segment): void {
                $q->where('oracle_cards.searchable_name', 'like', "%$segment%")
                    ->orWhereExists(function (QueryBuilder $sub) use ($segment): void {
                        $sub->select(DB::raw(1))
                            ->from(self::ORACLE_TRANSLATION_TABLE.' as oct')
                            ->whereColumn('oct.oracle_card_id', 'oracle_cards.id')
                            ->where('oct.searchable_name', 'like', "%$segment%");
                    })
                    ->orWhereExists(function (QueryBuilder $sub) use ($segment): void {
                        $sub->select(DB::raw(1))
                            ->from(self::FACE_TRANSLATION_TABLE.' as ofct')
                            ->whereColumn('ofct.oracle_card_id', 'oracle_cards.id')
                            ->where('ofct.searchable_name', 'like', "%$segment%");
                    });
            });
        }
    }
}
