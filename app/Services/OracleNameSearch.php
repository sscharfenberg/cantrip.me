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
 *                    AND oct.lang IN (…SEARCHABLE_LANGS…)
 *                    AND oct.searchable_name LIKE '%$s%')
 *       OR EXISTS (SELECT 1 FROM oracle_card_face_translations ofct
 *                  WHERE ofct.oracle_card_id = oracle_cards.id
 *                    AND ofct.lang IN (…SEARCHABLE_LANGS…)
 *                    AND ofct.searchable_name LIKE '%$s%')
 *     )
 *
 * Repeated per segment so multi-word queries still AND across
 * segments — each segment can independently match the English name
 * or any translation; the outer AND across segments preserves the
 * existing semantics for plain English search.
 *
 * **Why a lang allowlist:** Scryfall ships translations in 17 non-
 * English languages, but the seven promo/joke languages (Phyrexian,
 * Ancient Greek, Quenya, Hebrew, Arabic, Sanskrit, Latin) collectively
 * contribute ~42 rows — search hits there are essentially zero. The
 * top-10 allowlist (de/fr/ja/it/es/pt/zhs/ru/zht/ko) covers >99.99% of
 * realistic foreign-language search traffic. Filtering up front lets
 * the optimizer use the `(lang, searchable_name)` composite index to
 * slice the translation tables before the LIKE scan, instead of
 * falling back to the FK join column with no slicing.
 *
 * The allowlist is hardcoded for now — `users.locale` is `de` or `en`
 * for every account today, so per-user filtering would just be a more
 * complex no-op. Promote to a `lang IN (user.locale, 'en')` slice if/
 * when the user base diversifies.
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
     * Languages eligible for foreign-language name search. Ordered by
     * row-count descending — the order doesn't affect query planning
     * (MySQL/MariaDB sort the IN list internally for index seeks) but
     * documents which languages drive the bulk of matches.
     *
     * Excludes the seven micro-language cases — `ph`, `grc`, `qya`,
     * `he`, `ar`, `sa`, `la` — which collectively contribute ~42 rows
     * out of ~246k and represent promo / joke prints that no user
     * would realistically search by.
     *
     * @var list<string>
     */
    public const SEARCHABLE_LANGS = ['de', 'fr', 'ja', 'it', 'es', 'pt', 'zhs', 'ru', 'zht', 'ko'];

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
                            ->whereIn('oct.lang', self::SEARCHABLE_LANGS)
                            ->where('oct.searchable_name', 'like', "%$segment%");
                    })
                    ->orWhereExists(function (QueryBuilder $sub) use ($segment): void {
                        $sub->select(DB::raw(1))
                            ->from(self::FACE_TRANSLATION_TABLE.' as ofct')
                            ->whereColumn('ofct.oracle_card_id', 'oracle_cards.id')
                            ->whereIn('ofct.lang', self::SEARCHABLE_LANGS)
                            ->where('ofct.searchable_name', 'like', "%$segment%");
                    });
            });
        }
    }
}
