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
 * **Ranking is translation-aware** (it was not originally). Ranking by
 * `oracle_cards.searchable_name` alone left a foreign-only match with
 * nothing to score against, so it fell through to the length/alphabetical
 * tiebreaker — ordered by how long its ENGLISH name happens to be. That
 * was recorded as acceptable for distinctive names, on the reasoning that
 * "Blitzschlag" is uniquely Lightning Bolt. The failure mode is subtler
 * than that: distinctiveness of the CARD is not distinctiveness of the
 * normalized QUERY. "稲妻" is a translation belonging to exactly one card,
 * but it folds to "dao qi" — two short tokens that many cards match — so
 * Lightning Bolt placed 21st of 20 behind six-letter names.
 *
 * {@see translationMatchesSql} now lets the rank CASE score an exact or
 * prefix translation match in the same tier as the English equivalent,
 * using the `(lang, searchable_name)` index so it stays two seeks.
 */
final class OracleNameSearch
{
    public const ORACLE_TRANSLATION_TABLE = 'oracle_card_translations';

    public const FACE_TRANSLATION_TABLE = 'oracle_card_face_translations';

    /**
     * Both translation tables, in the order their bindings are consumed.
     *
     * @var list<string>
     */
    public const TRANSLATION_TABLES = [self::ORACLE_TRANSLATION_TABLE, self::FACE_TRANSLATION_TABLE];

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
     * For a set of oracle IDs the caller already pre-filtered via
     * {@see applyMultiTableNameSegments}, find a translation row (per
     * oracle) whose `searchable_name` contains *every* segment — i.e.
     * a single foreign-language printing that explains the match.
     * Used to render a "matched by translated name" badge next to
     * search-result cards (e.g. typing "Blitz" surfaces Aether Flash,
     * and we annotate that result with its DE printed_name "Ätherblitz").
     *
     * **English wins silently.** Oracles whose own `searchable_name`
     * already contains every segment are skipped — the result needs
     * no annotation because the English name explains the match.
     * "Blitzball Stadium" stays silent for query "Blitz"; "Aether
     * Flash" gets the DE badge.
     *
     * **Why AND-across-segments here, even though the predicate in
     * applyMultiTableNameSegments allows mixed-table matches per
     * segment.** The result list does include oracles matched by
     * scattering segments across the three tables — but no single
     * translation row "explains" that match, so there's nothing
     * coherent to show as a badge. Falling through to silent is the
     * right UX for the mixed case.
     *
     * **Lang preference.** If `$preferredLang` is in the allowlist
     * and has a matching translation, it wins (so a German user who
     * typed an ambiguous term keeps seeing DE when DE is among the
     * matches). Otherwise the first lang in {@see SEARCHABLE_LANGS}
     * order wins — deterministic, biased toward higher-row-count
     * languages.
     *
     * **Cost.** Two indexed queries on small composite-PK tables,
     * filtered to ≤200 oracle IDs and the lang allowlist. Negligible
     * compared to the existing phase-1 + phase-2 + join cost.
     *
     * @param  array<string, string>  $oracleSearchableNamesById  [oracle_card_id => searchable_name] from phase 1.
     * @param  string[]  $segments  Normalized segments (from {@see CardNameNormalizer::normalize}).
     * @param  string|null  $preferredLang  User's locale ('de' / 'en' / null). Used as a tiebreaker.
     * @return array<string, array{lang: string, name: string}> Keyed by oracle_card_id; only oracles with a coherent translation match are present.
     */
    public static function resolveMatchedTranslations(
        array $oracleSearchableNamesById,
        array $segments,
        ?string $preferredLang = null,
    ): array {
        if ($oracleSearchableNamesById === [] || $segments === []) {
            return [];
        }

        // Filter out oracles whose English searchable_name already
        // matches every segment — those are silent by spec.
        $candidateIds = [];
        foreach ($oracleSearchableNamesById as $id => $searchable) {
            foreach ($segments as $segment) {
                if (! str_contains($searchable, $segment)) {
                    $candidateIds[] = $id;

                    continue 2;
                }
            }
        }
        if ($candidateIds === []) {
            return [];
        }

        // Collect candidate (oracle, lang, printed_name) rows from
        // both translation tables. Per-table AND-across-segments
        // narrows to translations that fully explain the match.
        // Oracle-level table is queried first so it wins over face-
        // level on the rare oracle where both have a match for the
        // same lang (the oracle-level translation is the canonical
        // "printed name" for the whole card).
        /** @var array<string, array<string, string>> $byOracle */
        $byOracle = [];
        foreach ([self::ORACLE_TRANSLATION_TABLE, self::FACE_TRANSLATION_TABLE] as $table) {
            $q = DB::table($table)
                ->whereIn('oracle_card_id', $candidateIds)
                ->whereIn('lang', self::SEARCHABLE_LANGS);
            foreach ($segments as $segment) {
                $q->where('searchable_name', 'like', "%$segment%");
            }
            foreach ($q->get(['oracle_card_id', 'lang', 'printed_name']) as $row) {
                $byOracle[$row->oracle_card_id][$row->lang] ??= $row->printed_name;
            }
        }

        // Pick the badge lang per oracle.
        $result = [];
        foreach ($byOracle as $oracleId => $langs) {
            $pick = null;
            if ($preferredLang !== null && isset($langs[$preferredLang])) {
                $pick = $preferredLang;
            } else {
                foreach (self::SEARCHABLE_LANGS as $lang) {
                    if (isset($langs[$lang])) {
                        $pick = $lang;
                        break;
                    }
                }
            }
            if ($pick !== null) {
                $result[$oracleId] = ['lang' => $pick, 'name' => $langs[$pick]];
            }
        }

        return $result;
    }

    /**
     * For a set of oracle IDs, list the foreign languages each oracle
     * has any translation in. Drives the card-stack page's language
     * picker: once a card is selected, the picker narrows to langs
     * the card was actually printed in (English is always implicit
     * and not included in the returned array — every card has an
     * English oracle name).
     *
     * **Granularity caveat.** The translation tables are deduped at
     * the oracle level — one row per `(oracle_id, lang)` — so this
     * reflects "*some* Scryfall printing of this oracle exists in
     * lang X", not necessarily "the user's currently picked printing
     * does". Correct for the standard print-runs of every modern set;
     * over-permits for English-only outliers (Mystery Booster, The
     * List, some Secret Lair drops). Deliberate trade-off — see
     * `docs/foreign-language-search.md` notes / commit history.
     *
     * Filtered to {@see SEARCHABLE_LANGS}, so Phyrexian / Latin /
     * other curiosities never reach the picker even when present in
     * the underlying tables.
     *
     * @param  string[]  $oracleIds
     * @return array<string, string[]> oracle_card_id => sorted list of langs
     */
    public static function availableLangsByOracle(array $oracleIds): array
    {
        if ($oracleIds === []) {
            return [];
        }

        /** @var array<string, array<string, true>> $byOracle */
        $byOracle = [];
        foreach ([self::ORACLE_TRANSLATION_TABLE, self::FACE_TRANSLATION_TABLE] as $table) {
            $rows = DB::table($table)
                ->whereIn('oracle_card_id', $oracleIds)
                ->whereIn('lang', self::SEARCHABLE_LANGS)
                ->distinct()
                ->get(['oracle_card_id', 'lang']);
            foreach ($rows as $row) {
                $byOracle[$row->oracle_card_id][$row->lang] = true;
            }
        }

        $result = [];
        foreach ($byOracle as $oracleId => $langSet) {
            $langs = array_keys($langSet);
            // Sort by SEARCHABLE_LANGS order (rough row-count desc)
            // so the picker presents the same lang ordering as the
            // search backend prefers — deterministic and consistent
            // across the app.
            usort($langs, fn (string $a, string $b) => array_search($a, self::SEARCHABLE_LANGS, true) <=> array_search($b, self::SEARCHABLE_LANGS, true));
            $result[$oracleId] = $langs;
        }

        return $result;
    }

    /**
     * A correlated EXISTS testing whether any searchable translation of the
     * oracle card matches `$value` with the given LIKE/equality operator.
     *
     * Emitted as raw SQL rather than a builder callback because its consumer
     * is an ORDER BY CASE, which takes a string. Bindings stay out of the
     * fragment and are returned to the caller to pass positionally.
     *
     * Uses `(lang, searchable_name)` — the composite index on that table — so
     * both the `=` and the `LIKE 'x%'` form are index seeks rather than scans.
     * A leading-wildcard match would not be, which is why ranking only ever
     * asks these two questions.
     */
    public static function translationMatchesSql(string $operator): string
    {
        $langs = "'".implode("','", self::SEARCHABLE_LANGS)."'";

        // Both translation tables, because {@see applyMultiTableNameSegments}
        // ORs both when matching. Covering only the oracle-level table would
        // leave a card matched solely through a FACE translation scoring
        // nothing and falling back to the English-name-length tiebreaker —
        // i.e. the same bug this ranking exists to fix, still open for
        // multi-faced cards.
        $branches = [];
        foreach (self::TRANSLATION_TABLES as $index => $table) {
            $alias = "rank_t$index";
            $branches[] = "EXISTS (SELECT 1 FROM $table AS $alias"
                ." WHERE $alias.oracle_card_id = oracle_cards.id"
                ." AND $alias.lang IN ($langs)"
                ." AND $alias.searchable_name $operator ?)";
        }

        return '('.implode(' OR ', $branches).')';
    }

    /**
     * How many bindings {@see translationMatchesSql} consumes, so callers
     * building an ORDER BY can supply them positionally without counting the
     * tables themselves.
     */
    public static function translationMatchesBindingCount(): int
    {
        // Derived, not stated. The SQL emits one placeholder per table, so a
        // hardcoded count silently misaligns EVERY later binding in the
        // caller's ORDER BY the moment a third translation table appears —
        // no error, just wrong ranking.
        return count(self::TRANSLATION_TABLES);
    }

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
