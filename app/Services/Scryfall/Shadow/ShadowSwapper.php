<?php

namespace App\Services\Scryfall\Shadow;

use Throwable;

/**
 * The swap at the end of `scryfall:update`: put the shadow build live
 * without losing a single FK constraint on the way.
 *
 * Postgres FK constraints follow a renamed parent table, so every FK
 * pointing at a swap table — the internal Scryfall ones and the user-data
 * ones from `deck_cards`, `card_stacks` and the rest — is dropped before
 * the renames and re-added after them (see
 * {@see ShadowTableService::captureForeignKeys()}). All of it runs in ONE
 * transaction: Postgres DDL is transactional, so a failure anywhere — the
 * schema check, a rename, a re-added constraint the new data violates, a
 * killed process — rolls the drop back with it, and live stays exactly
 * as it was. There is no window in which constraints can get lost.
 *
 * The orchestrator's alerting wrapper is only called outside the
 * transaction. After a failed statement Postgres refuses every further
 * one until the rollback, and the alert writes its "already sent" marker
 * to the cache, which lives in the database.
 */
class ShadowSwapper
{
    public function __construct(private readonly ShadowTableService $tables) {}

    /**
     * $beforeRename runs as the step `validateSchema` inside the
     * transaction, directly before the renames: the last moment a check
     * can still stop the swap, so a deploy migrating a swap table has the
     * smallest possible window to slip in unseen.
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
        });
        $step('analyzeShadows', fn () => $this->tables->analyzeShadows());

        $current = 'dropForeignKeys';
        try {
            $this->tables->transaction(function () use ($fks, $beforeRename, &$current): void {
                $this->tables->dropForeignKeys($fks);
                $current = 'validateSchema';
                $beforeRename();
                $current = 'swap';
                $this->tables->swap();
                $current = 'addForeignKeys';
                $this->tables->addForeignKeys($fks);
            });
        } catch (Throwable $e) {
            // Rolled back by now; report it under the step that failed.
            $step($current, fn () => throw $e);

            throw $e;
        }

        return count($fks);
    }
}
