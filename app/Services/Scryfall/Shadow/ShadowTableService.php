<?php

/** @noinspection SqlNoDataSourceInspection */

namespace App\Services\Scryfall\Shadow;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Low-level shadow-table operations: cleanup, create-LIKE, FK capture,
 * swap. Postgres-specific; do not call against SQLite.
 *
 * No knowledge of which scryfall tables exist — defers to
 * {@see ShadowTableRegistry}.
 */
class ShadowTableService
{
    /**
     * How long a statement of the swap waits for a lock before the whole
     * swap gives up. The swap locks every swap table and every table whose
     * FK points into one; a long-running read on any of them would
     * otherwise hold the swap — and everything queued behind its locks,
     * i.e. the site — for as long as that read takes.
     */
    private const LOCK_TIMEOUT = '10s';

    /**
     * Drop any leftover __shadow / __retired tables for every registered
     * scryfall table. Called at the start of every scryfall:update run so
     * a failed prior run cannot leak state into the next one. Shadow
     * tables carry no FK constraints, and the swap drops its retired
     * tables in the transaction that creates them, so nothing references
     * what is dropped here.
     */
    public function cleanup(): void
    {
        foreach (ShadowTableRegistry::TABLES as $table) {
            $this->dropTableIfExists(ShadowTableRegistry::shadow($table));
            $this->dropTableIfExists(ShadowTableRegistry::retired($table));
        }
        Log::channel('scryfall')->debug('ShadowTableService: cleanup completed.');
    }

    /**
     * Create an empty <table>__shadow with the same schema as <table>.
     *
     * `LIKE … INCLUDING ALL` copies columns, defaults, CHECK constraints
     * and indexes, but never FK constraints, so the shadow build is
     * unconstrained: the orchestrator checks integrity with
     * {@see ShadowValidationService} before the swap, and the swap re-adds
     * the live tables' constraints. The copied indexes get names derived
     * from the shadow table's; {@see swap()} gives them the live names
     * back.
     */
    public function createLike(string $table): void
    {
        $shadow = ShadowTableRegistry::shadow($table);
        $this->dropTableIfExists($shadow);
        DB::statement("CREATE TABLE \"$shadow\" (LIKE \"$table\" INCLUDING ALL)");
        Log::channel('scryfall')->debug("ShadowTableService: created \"$shadow\" LIKE \"$table\".");
    }

    /**
     * Whether this session's commits wait until their WAL is on disk
     * (`synchronous_commit`). The shadow build commits several hundred
     * thousand single-row writes, and waiting for the disk on each one
     * (~1.5 ms) cost about half of a sync's run time. Without the wait, a
     * crash loses at most the last fraction of a second of commits and
     * corrupts nothing — and a crashed build is thrown away by the next
     * run's {@see cleanup()} anyway. Turned back on before the swap, whose
     * commit must survive a crash.
     */
    public function waitForDiskOnCommit(bool $wait): void
    {
        DB::statement($wait ? 'RESET synchronous_commit' : 'SET synchronous_commit = off');
        Log::channel('scryfall')->debug('ShadowTableService: synchronous_commit '.($wait ? 'reset' : 'off').'.');
    }

    /**
     * Collect planner statistics for the freshly built shadow tables, so
     * the first queries after the swap are not planned blind. Run before
     * the swap's transaction — it reads every table and has no business
     * holding the swap's locks while it does.
     *
     * @param  array<int, string>  $tables  live table names; defaults to every registered table.
     */
    public function analyzeShadows(array $tables = ShadowTableRegistry::TABLES): void
    {
        foreach ($tables as $table) {
            DB::statement('ANALYZE "'.ShadowTableRegistry::shadow($table).'"');
        }
        Log::channel('scryfall')->debug('ShadowTableService: shadow tables analyzed.');
    }

    /**
     * Capture every FK constraint that references one of the registered
     * scryfall tables — the internal ones between swap tables and the
     * user-data ones from deck_cards, card_stacks and the rest.
     *
     * Why: Postgres FK constraints reference their parent table, not its
     * name. When the swap renames `sets` → `sets__retired`, every FK
     * pointing at `sets` follows it, and dropping the retired table would
     * then fail on them. So {@see ShadowSwapper} drops these before the
     * swap and re-adds them after it, inside the same transaction; the
     * re-added definitions name `sets`, which by then is the new table.
     *
     * @param  array<int, string>  $tables  referenced (parent) table names; defaults to every registered table.
     * @return list<object{table_name: string, name: string, referenced_table: string, definition: string}>
     */
    public function captureForeignKeys(array $tables = ShadowTableRegistry::TABLES): array
    {
        $placeholders = implode(',', array_fill(0, count($tables), '?'));

        return DB::select("
            SELECT
                child.relname AS table_name,
                con.conname AS name,
                parent.relname AS referenced_table,
                pg_get_constraintdef(con.oid) AS definition
            FROM pg_constraint con
            JOIN pg_class child ON child.oid = con.conrelid
            JOIN pg_class parent ON parent.oid = con.confrelid
            JOIN pg_namespace ns ON ns.oid = parent.relnamespace
            WHERE con.contype = 'f'
            AND ns.nspname = current_schema()
            AND parent.relname IN ($placeholders)
            ORDER BY child.relname, con.conname
        ", $tables);
    }

    /**
     * Drop every FK constraint in the given list.
     *
     * @param  array<int, object>  $fks  As returned by {@see captureForeignKeys()}.
     */
    public function dropForeignKeys(array $fks): void
    {
        foreach ($fks as $fk) {
            DB::statement("ALTER TABLE \"{$fk->table_name}\" DROP CONSTRAINT \"{$fk->name}\"");
        }
        Log::channel('scryfall')->debug('ShadowTableService: dropped '.count($fks).' FK constraints before swap.');
    }

    /**
     * Add every FK constraint in the given list back, under its old name
     * and definition. Adding a constraint validates the rows already in
     * the table, so a reference the new dataset cannot satisfy fails the
     * swap rather than slipping into live.
     *
     * @param  array<int, object>  $fks  As returned by {@see captureForeignKeys()}.
     * @return int Constraints added.
     */
    public function addForeignKeys(array $fks): int
    {
        foreach ($fks as $fk) {
            DB::statement("ALTER TABLE \"{$fk->table_name}\" ADD CONSTRAINT \"{$fk->name}\" {$fk->definition}");
        }
        Log::channel('scryfall')->debug('ShadowTableService: re-added '.count($fks).' FK constraints.');

        return count($fks);
    }

    /**
     * Run `$work` in one transaction, so the FK drop, the swap and the
     * re-add take effect together or not at all — Postgres DDL is
     * transactional. Locks are waited for at most {@see LOCK_TIMEOUT}.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        return DB::transaction(function () use ($work): mixed {
            DB::statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");

            return $work();
        });
    }

    /**
     * Swap every shadow into live: each live table is renamed to
     * __retired, its shadow takes the live name, the retired table is
     * dropped, and the new table's indexes take the names the old one's
     * had. Run it inside {@see transaction()} with the FK constraints
     * dropped — it is only atomic there, and the retired tables cannot be
     * dropped while constraints still reference them.
     *
     * Why the indexes are renamed: index names are unique per schema, so
     * the shadow's copies were created as `sets__shadow_pkey` and the
     * like. Left that way, a later migration that drops or renames an
     * index by its Laravel name would fail, and the next run's copies
     * would drift further from the names the migrations know.
     *
     * @param  array<int, string>  $tables  live table names to swap; defaults to every registered table.
     */
    public function swap(array $tables = ShadowTableRegistry::TABLES): void
    {
        $indexNames = [];
        foreach ($tables as $table) {
            $indexNames[$table] = $this->pairIndexNames($table, ShadowTableRegistry::shadow($table));
        }

        foreach ($tables as $table) {
            $shadow = ShadowTableRegistry::shadow($table);
            $retired = ShadowTableRegistry::retired($table);
            DB::statement("ALTER TABLE \"$table\" RENAME TO \"$retired\"");
            DB::statement("ALTER TABLE \"$shadow\" RENAME TO \"$table\"");
        }

        foreach ($tables as $table) {
            DB::statement('DROP TABLE "'.ShadowTableRegistry::retired($table).'"');
        }

        foreach ($indexNames as $pairs) {
            foreach ($pairs as $shadowName => $liveName) {
                if ($shadowName !== $liveName) {
                    DB::statement("ALTER INDEX \"$shadowName\" RENAME TO \"$liveName\"");
                }
            }
        }

        Log::channel('scryfall')->info('ShadowTableService: swap completed.', [
            'tables' => $tables,
        ]);
    }

    /**
     * Pair each index of the shadow table with the live table's index of
     * the same shape.
     *
     * @return array<string, string> shadow index name => live index name
     */
    private function pairIndexNames(string $live, string $shadow): array
    {
        $liveIndexes = $this->indexesByShape($live);
        $shadowIndexes = $this->indexesByShape($shadow);

        $pairs = [];
        foreach ($shadowIndexes as $shape => $names) {
            $liveNames = $liveIndexes[$shape] ?? [];
            if (count($liveNames) !== count($names)) {
                throw new RuntimeException("the indexes of \"$shadow\" do not match those of \"$live\" ($shape) — its schema changed during the run.");
            }
            foreach ($names as $i => $name) {
                $pairs[$name] = $liveNames[$i];
            }
        }
        if (array_diff_key($liveIndexes, $shadowIndexes) !== []) {
            throw new RuntimeException("\"$live\" has indexes \"$shadow\" lacks — its schema changed during the run.");
        }

        return $pairs;
    }

    /**
     * A table's index names, grouped by what the index is: its definition
     * with the index and table names cut out, prefixed by PRIMARY for the
     * primary key. Names within a group are sorted, so two identical
     * indexes pair up the same way every time.
     *
     * @return array<string, list<string>>
     */
    private function indexesByShape(string $table): array
    {
        $rows = DB::select("
            SELECT
                idx.relname AS name,
                (CASE WHEN i.indisprimary THEN 'PRIMARY ' ELSE '' END)
                    || regexp_replace(pg_get_indexdef(i.indexrelid), '^CREATE (UNIQUE )?INDEX \\S+ ON \\S+ ', '\\1') AS shape
            FROM pg_index i
            JOIN pg_class idx ON idx.oid = i.indexrelid
            WHERE i.indrelid = ?::regclass
            ORDER BY idx.relname
        ", ["\"$table\""]);

        $byShape = [];
        foreach ($rows as $row) {
            $byShape[$row->shape][] = $row->name;
        }

        return $byShape;
    }

    private function dropTableIfExists(string $table): void
    {
        DB::statement("DROP TABLE IF EXISTS \"$table\"");
    }
}
