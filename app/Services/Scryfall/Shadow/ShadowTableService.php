<?php

/** @noinspection SqlNoDataSourceInspection */

namespace App\Services\Scryfall\Shadow;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

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
     * Where {@see rememberForeignKeys()} keeps the FK constraints dropped
     * around the swap until they are back, on the `local` disk
     * (storage/app/private — per environment, unlike the shared image
     * folders). At the disk root on purpose: that folder already exists
     * as 2775 deploy:www-data, while a subfolder Flysystem creates would
     * be 0700 and unreadable for a run under another user.
     */
    public const PENDING_FOREIGN_KEYS = 'scryfall-pending-foreign-keys.json';

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
     * Add every FK constraint in the given list back. Run after the swap
     * so the parent table names resolve to the new live tables — or, when
     * the swap failed, to the old ones, which still own them. FK checks
     * disabled during ALTER so existing data isn't re-validated (it was
     * already validated by {@see ShadowValidationService} pre-swap).
     *
     * A constraint that already exists pointing at the right table is
     * skipped, so the call can finish a re-add that broke off halfway, or
     * follow a drop that did. One that exists but points elsewhere — a
     * survivor of the drop that the RENAME rotated onto a `__retired`
     * table — is dropped and re-added: skipped, it would dangle once the
     * retired table is dropped.
     *
     * @param  array<int, object>  $fks  As returned by {@see captureForeignKeys()}.
     * @return int Constraints actually added.
     */
    public function addForeignKeys(array $fks): int
    {
        $existing = [];
        $rows = DB::select(
            'SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?',
            [DB::connection()->getDatabaseName()]
        );
        foreach ($rows as $row) {
            $existing["$row->TABLE_NAME.$row->CONSTRAINT_NAME"] = $row->REFERENCED_TABLE_NAME;
        }

        $added = 0;
        $this->withForeignKeyChecksDisabled(function () use ($fks, $existing, &$added): void {
            foreach ($fks as $fk) {
                $references = $existing["$fk->TABLE_NAME.$fk->CONSTRAINT_NAME"] ?? null;
                if ($references === $fk->REFERENCED_TABLE_NAME) {
                    continue;
                }
                if ($references !== null) {
                    DB::statement("ALTER TABLE `{$fk->TABLE_NAME}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                }
                DB::statement(
                    "ALTER TABLE `{$fk->TABLE_NAME}` ADD CONSTRAINT `{$fk->CONSTRAINT_NAME}` "
                    ."FOREIGN KEY (`{$fk->COLUMN_NAME}`) REFERENCES `{$fk->REFERENCED_TABLE_NAME}` (`{$fk->REFERENCED_COLUMN_NAME}`) "
                    ."ON DELETE {$fk->DELETE_RULE} ON UPDATE {$fk->UPDATE_RULE}"
                );
                $added++;
            }
        });
        Log::channel('scryfall')->debug("ShadowTableService: re-added $added of ".count($fks).' FK constraints.');

        return $added;
    }

    /**
     * Write the FK constraints about to be dropped to disk, so a run that
     * dies before {@see addForeignKeys()} has put them back — a failed
     * re-add, an out-of-memory kill — does not lose them. The next run's
     * {@see restorePendingForeignKeys()} re-adds them. Without it they
     * were gone for good: that run's capture finds nothing to capture,
     * re-adds nothing and reports success.
     *
     * @param  array<int, object>  $fks  As returned by {@see captureForeignKeys()}.
     */
    public function rememberForeignKeys(array $fks): void
    {
        if (! Storage::disk('local')->put(self::PENDING_FOREIGN_KEYS, json_encode($fks, JSON_THROW_ON_ERROR), 'public')) {
            throw new RuntimeException('could not write '.self::PENDING_FOREIGN_KEYS.' to storage/app/private — refusing to drop FK constraints without a record of them.');
        }
    }

    /** Clear the record once the constraints are back. */
    public function forgetForeignKeys(): void
    {
        Storage::disk('local')->delete(self::PENDING_FOREIGN_KEYS);
    }

    /**
     * Re-add the FK constraints an earlier run dropped and never put back,
     * see {@see rememberForeignKeys()}. Run before anything else in
     * `scryfall:update`. Whether that run died before or after its swap,
     * the constraints name the live tables, which exist either way.
     *
     * Each constraint is tried on its own and the record is cleared
     * whatever happens. A constraint that cannot come back — a migration
     * since then dropped its column, say — is reported for the caller to
     * alert about rather than thrown, so one stale record cannot stop
     * every following night's sync at its first step.
     *
     * @return array{restored: int, failed: list<string>} `failed` names each
     *                                                    constraint that could
     *                                                    not be re-added, and why.
     */
    public function restorePendingForeignKeys(): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::PENDING_FOREIGN_KEYS)) {
            return ['restored' => 0, 'failed' => []];
        }

        try {
            $fks = array_map(
                fn (array $fk): object => (object) $fk,
                json_decode((string) $disk->get(self::PENDING_FOREIGN_KEYS), true, flags: JSON_THROW_ON_ERROR),
            );
        } catch (JsonException $e) {
            $this->forgetForeignKeys();

            return ['restored' => 0, 'failed' => [self::PENDING_FOREIGN_KEYS.' is unreadable ('.$e->getMessage().')']];
        }

        $restored = 0;
        $failed = [];
        foreach ($fks as $fk) {
            try {
                $restored += $this->addForeignKeys([$fk]);
            } catch (Throwable $e) {
                $failed[] = "$fk->TABLE_NAME.$fk->CONSTRAINT_NAME ({$e->getMessage()})";
            }
        }
        $this->forgetForeignKeys();

        if ($restored > 0) {
            Log::channel('scryfall')->warning("ShadowTableService: re-added $restored FK constraints an earlier run dropped and never restored.");
        }
        if ($failed !== []) {
            Log::channel('scryfall')->error('ShadowTableService: could not re-add FK constraints an earlier run dropped: '.implode('; ', $failed));
        }

        return ['restored' => $restored, 'failed' => $failed];
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
