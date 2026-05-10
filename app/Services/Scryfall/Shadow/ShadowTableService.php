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
     * Capture every FK constraint that references one of the registered
     * scryfall tables. Used by the orchestrator to drop these BEFORE the
     * swap and re-add them AFTER.
     *
     * Why we need this: MariaDB auto-rotates FK references when the
     * referenced (parent) table is renamed. So when the swap renames
     * `sets` → `sets__retired`, every FK pointing at `sets` (including
     * user-data FKs from deck_cards/card_stacks/etc.) silently rotates
     * to point at `sets__retired`. After dropRetired, those FKs reference
     * a table that no longer exists — every user-data write that triggers
     * FK validation breaks.
     *
     * The fix: drop these FKs before the swap, do the swap (no FKs to
     * rotate), drop retired, then re-add the FKs. After re-add the
     * references resolve to the new live tables (which now own the names
     * the FKs target).
     *
     * @return array<int, object{TABLE_NAME: string, CONSTRAINT_NAME: string, COLUMN_NAME: string, REFERENCED_TABLE_NAME: string, REFERENCED_COLUMN_NAME: string, UPDATE_RULE: string, DELETE_RULE: string}>
     */
    public function captureForeignKeys(array $tables = ShadowTableRegistry::TABLES): array
    {
        $database = DB::connection()->getDatabaseName();
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $params = array_merge([$database], $tables);

        $sql = "
            SELECT
                kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE, rc.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = ?
            AND kcu.REFERENCED_TABLE_NAME IN ($placeholders)
        ";

        return DB::select($sql, $params);
    }

    /**
     * Drop every FK constraint in the given list. FK checks disabled so
     * the drop succeeds regardless of current data state.
     *
     * @param  array<int, object>  $fks  As returned by {@see captureForeignKeys()}.
     */
    public function dropForeignKeys(array $fks): void
    {
        $this->withForeignKeyChecksDisabled(function () use ($fks): void {
            foreach ($fks as $fk) {
                DB::statement("ALTER TABLE `{$fk->TABLE_NAME}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
            }
        });
        Log::channel('scryfall')->debug('ShadowTableService: dropped '.count($fks).' FK constraints before swap.');
    }

    /**
     * Add every FK constraint in the given list back. Run AFTER the swap
     * and AFTER dropRetired so the parent table names resolve to the
     * new live tables. FK checks disabled during ALTER so existing data
     * isn't re-validated (it was already validated by
     * {@see ShadowValidationService} pre-swap).
     *
     * @param  array<int, object>  $fks  As returned by {@see captureForeignKeys()}.
     */
    public function addForeignKeys(array $fks): void
    {
        $this->withForeignKeyChecksDisabled(function () use ($fks): void {
            foreach ($fks as $fk) {
                DB::statement(
                    "ALTER TABLE `{$fk->TABLE_NAME}` ADD CONSTRAINT `{$fk->CONSTRAINT_NAME}` "
                    ."FOREIGN KEY (`{$fk->COLUMN_NAME}`) REFERENCES `{$fk->REFERENCED_TABLE_NAME}` (`{$fk->REFERENCED_COLUMN_NAME}`) "
                    ."ON DELETE {$fk->DELETE_RULE} ON UPDATE {$fk->UPDATE_RULE}"
                );
            }
        });
        Log::channel('scryfall')->debug('ShadowTableService: re-added '.count($fks).' FK constraints after swap.');
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
