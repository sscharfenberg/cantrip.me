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
}
