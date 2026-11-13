<?php

namespace App\Services\Scryfall\Shadow;

/**
 * The swap at the end of `scryfall:update`: put the shadow build live
 * without losing a single FK constraint on the way.
 *
 * MariaDB rotates FK references when a parent table is renamed, so every
 * FK pointing at a swap table — the internal Scryfall ones and the
 * user-data ones from `deck_cards`, `card_stacks` and the rest — is
 * dropped before the RENAME and re-added after it (see
 * {@see ShadowTableService::captureForeignKeys()}). The window between
 * the two is the one place a failure can do lasting damage, so:
 *
 *   - the captured list is written to disk before the first drop, and a
 *     run that dies anyway has its constraints restored by the next one
 *     ({@see ShadowTableService::restorePendingForeignKeys()});
 *   - the re-add runs whether or not the drop and the swap succeeded,
 *     and it skips constraints that still exist;
 *   - the last check before the RENAME runs inside that window, so no
 *     failure there can leave the constraints dropped;
 *   - it runs before the `__retired` tables are dropped, which is only
 *     housekeeping and must not stand between the live tables and their
 *     constraints.
 */
class ShadowSwapper
{
    public function __construct(private readonly ShadowTableService $tables) {}

    /**
     * $beforeRename runs as the step `validateSchema` directly before the
     * RENAME, once the constraints are dropped: the last moment a check
     * can still stop the swap, so a deploy migrating a swap table has the
     * smallest possible window to slip in unseen. When it throws, the
     * constraints are re-added like after any other failure.
     *
     * @param  callable(string, callable(): void): void  $step  Runs one named step and rethrows its failure — the orchestrator's alerting wrapper.
     * @param  callable(): void  $beforeRename  Throws to stop the swap.
     * @return int FK constraints dropped around the swap and re-added.
     */
    public function swapIntoLive(callable $step, callable $beforeRename): int
    {
        $fks = [];
        $step('captureForeignKeys', function () use (&$fks): void {
            $fks = $this->tables->captureForeignKeys();
            $this->tables->rememberForeignKeys($fks);
        });

        try {
            $step('dropForeignKeys', fn () => $this->tables->dropForeignKeys($fks));
            $step('validateSchema', $beforeRename);
            $step('swap', fn () => $this->tables->swap());
        } finally {
            $step('addForeignKeys', function () use ($fks): void {
                $this->tables->addForeignKeys($fks);
                $this->tables->forgetForeignKeys();
            });
        }

        $step('dropRetired', fn () => $this->tables->dropRetired());

        return count($fks);
    }
}
