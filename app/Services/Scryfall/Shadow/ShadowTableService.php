<?php

/** @noinspection SqlNoDataSourceInspection */

namespace App\Services\Scryfall\Shadow;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Low-level shadow-table operations: cleanup, create-LIKE, atomic swap,
 * drop-retired. All MariaDB-specific; do not call against SQLite.
 *
 * No knowledge of which scryfall tables exist — defers to
 * {@see ShadowTableRegistry}.
 */
class ShadowTableService
{
    /**
     * Drop any leftover __shadow / __retired tables for every registered
     * scryfall table. Called at the start of every scryfall:update run so
     * a crashed prior run cannot leak state into the next one.
     *
     * FK checks are disabled because retired tables may still carry FKs
     * pointing at each other from when they were the live set.
     */
    public function cleanup(): void
    {
        $this->withForeignKeyChecksDisabled(function (): void {
            foreach (ShadowTableRegistry::TABLES as $table) {
                $this->dropTableIfExists(ShadowTableRegistry::shadow($table));
                $this->dropTableIfExists(ShadowTableRegistry::retired($table));
            }
        });
        Log::channel('scryfall')->debug('ShadowTableService: cleanup completed.');
    }

    /**
     * Create an empty <table>__shadow with the same schema as <table>.
     *
     * `CREATE TABLE LIKE` copies columns, indexes, and FK *definitions*.
     * The copied FKs still reference the live table names by string, so
     * the import must run with FK checks disabled and the orchestrator
     * must call {@see ShadowValidationService} before swap.
     */
    public function createLike(string $table): void
    {
        $shadow = ShadowTableRegistry::shadow($table);
        $this->dropTableIfExists($shadow);
        DB::statement("CREATE TABLE `$shadow` LIKE `$table`");
        Log::channel('scryfall')->debug("ShadowTableService: created `$shadow` LIKE `$table`.");
    }

    /**
     * Atomic multi-table RENAME swapping every shadow into live and the
     * current live into retired. Either every swap happens or none does.
     *
     * @param  array<int, string>  $tables  live table names to swap; defaults to every registered table.
     */
    public function swap(array $tables = ShadowTableRegistry::TABLES): void
    {
        $pairs = [];
        foreach ($tables as $table) {
            $shadow = ShadowTableRegistry::shadow($table);
            $retired = ShadowTableRegistry::retired($table);
            $pairs[] = "`$table` TO `$retired`";
            $pairs[] = "`$shadow` TO `$table`";
        }
        $sql = 'RENAME TABLE '.implode(', ', $pairs);
        DB::statement($sql);
        Log::channel('scryfall')->info('ShadowTableService: atomic swap completed.', [
            'tables' => $tables,
        ]);
    }

    /**
     * Drop the __retired tables left behind after {@see swap()}.
     *
     * @param  array<int, string>  $tables  live table names whose retired
     *                                      counterparts should be dropped;
     *                                      defaults to every registered table.
     */
    public function dropRetired(array $tables = ShadowTableRegistry::TABLES): void
    {
        $this->withForeignKeyChecksDisabled(function () use ($tables): void {
            foreach ($tables as $table) {
                $this->dropTableIfExists(ShadowTableRegistry::retired($table));
            }
        });
        Log::channel('scryfall')->debug('ShadowTableService: retired tables dropped.', [
            'tables' => $tables,
        ]);
    }

    private function dropTableIfExists(string $table): void
    {
        DB::statement("DROP TABLE IF EXISTS `$table`");
    }

    private function withForeignKeyChecksDisabled(callable $work): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $work();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
