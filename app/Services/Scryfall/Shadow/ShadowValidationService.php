<?php

namespace App\Services\Scryfall\Shadow;

use Illuminate\Support\Facades\DB;

/**
 * Pre-swap orphan validation. Walks every FK relation declared in
 * {@see ShadowTableRegistry::FK_CHECKS} and counts rows in the source
 * whose FK does not resolve against the shadow build of the target.
 *
 * Internal scryfall FKs (source mode 'shadow') protect us against bugs
 * in the import (e.g. default_cards row referencing an oracle UUID that
 * never made it into the shadow oracle table).
 *
 * User-data FKs (source mode 'live') protect users against Scryfall
 * removing a card any user references — the swap aborts and the live
 * dataset stays untouched.
 */
class ShadowValidationService
{
    /**
     * Live row count from which {@see findShrunkTables()} also checks the
     * proportion lost, not just emptiness. Below it (`bulk_data` with 7
     * rows, `symbols` with 84) one legitimately removed row is already a
     * large share.
     */
    public const SHRINK_CHECK_MIN_ROWS = 1000;

    /** Share of a table's live rows the shadow build may lack. */
    public const SHRINK_TOLERANCE = 0.1;

    /**
     * Run every FK check from the registry and return the violations.
     *
     * @param  array<int, array{0: string, 1: string, 2: string, 3: 'shadow'|'live'}>|null  $checks
     *                                                                                               Custom check set; defaults to {@see ShadowTableRegistry::FK_CHECKS}.
     *                                                                                               Provided so tests can exercise the validator against
     *                                                                                               throwaway fixtures.
     * @return array<int, array{source: string, fk: string, target: string, orphans: int}>
     *                                                                                     Empty when every FK resolves; one entry per violating relation otherwise.
     */
    public function findOrphans(?array $checks = null): array
    {
        $checks ??= ShadowTableRegistry::FK_CHECKS;

        $violations = [];
        foreach ($checks as [$source, $fk, $target, $sourceMode]) {
            $sourceTable = $sourceMode === 'shadow'
                ? ShadowTableRegistry::shadow($source)
                : $source;
            $targetTable = ShadowTableRegistry::shadow($target);

            $count = DB::table("$sourceTable as s")
                ->leftJoin("$targetTable as t", 't.id', '=', "s.$fk")
                ->whereNull('t.id')
                ->whereNotNull("s.$fk")
                ->count();

            if ($count > 0) {
                $violations[] = [
                    'source' => $sourceTable,
                    'fk' => $fk,
                    'target' => $targetTable,
                    'orphans' => $count,
                ];
            }
        }

        return $violations;
    }

    /**
     * Compare every shadow table's row count with its live table and
     * return the ones that shrank implausibly.
     *
     * The orphan check above only sees tables user data points into. A
     * step that wrote nothing — an empty `rulings__shadow` or translation
     * table — passes it, and the swap would replace the live rows with
     * none. Scryfall's dataset only ever loses a handful of rows a night,
     * so two rules cover it:
     *
     *   - a shadow table may not be empty while its live table has rows
     *     (this one also guards the small `bulk_data` and `symbols`), and
     *   - a table with at least {@see SHRINK_CHECK_MIN_ROWS} live rows may
     *     not lose more than {@see SHRINK_TOLERANCE} of them.
     *
     * A deliberate shrink (an import rule that now skips more rows) gets
     * past it with `scryfall:update --allow-shrink`.
     *
     * @param  array<int, string>|null  $tables  Live table names; defaults to {@see ShadowTableRegistry::TABLES}.
     *                                           Provided so tests can use throwaway tables.
     * @return list<array{table: string, live: int, shadow: int}> Empty when every table is plausible.
     */
    public function findShrunkTables(?array $tables = null): array
    {
        $shrunk = [];
        foreach ($tables ?? ShadowTableRegistry::TABLES as $table) {
            $live = DB::table($table)->count();
            $shadow = DB::table(ShadowTableRegistry::shadow($table))->count();

            $emptied = $live > 0 && $shadow === 0;
            $lostTooMany = $live >= self::SHRINK_CHECK_MIN_ROWS && $shadow < $live * (1 - self::SHRINK_TOLERANCE);
            if ($emptied || $lostTooMany) {
                $shrunk[] = ['table' => $table, 'live' => $live, 'shadow' => $shadow];
            }
        }

        return $shrunk;
    }
}
