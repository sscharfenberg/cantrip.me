<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Pdo\Mysql;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;

/**
 * Copy the data of a MariaDB cantrip database into this app's Postgres
 * database: the Scryfall dataset and every user's collection and decks,
 * so the move off MariaDB re-downloads nothing and loses nothing.
 *
 * The target is the default connection, which must be Postgres, migrated
 * and empty. The source is read inside one consistent snapshot, so a site
 * that is still serving cannot hand over a deck without its cards; rows
 * are streamed, so memory stays flat whatever the table size. Everything
 * is written in ONE Postgres transaction, in foreign-key order, and the
 * row count of every table is compared before it commits — a failure
 * anywhere leaves the target exactly as empty as it was.
 *
 * Every table both databases have is copied, except {@see SKIPPED_TABLES}.
 * A table or column only one side has stops the copy before anything is
 * written: the two databases are on different migrations.
 */
class DbCopyFromMariadb extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:copy-from-mariadb
        {--source-host=127.0.0.1 : MariaDB host}
        {--source-port=3306 : MariaDB port}
        {--source-database= : MariaDB database to read}
        {--source-username= : MariaDB user}
        {--source-password-stdin : Read the MariaDB password from standard input instead of prompting}
        {--force : Copy without asking for confirmation}';

    /**
     * @var string
     */
    protected $description = 'Copy every table of a MariaDB cantrip database into this (empty) Postgres database.';

    /**
     * Tables the move does not carry over. `migrations` is the target's own;
     * sessions (everyone logs in again), cache, queue and password-reset
     * tokens are worthless on a new server.
     */
    public const SKIPPED_TABLES = [
        'migrations',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ];

    private const SOURCE = 'mariadb_copy_source';

    /** Bound values per INSERT, well under Postgres's 65 535-parameter limit. */
    private const VALUES_PER_INSERT = 30_000;

    public function handle(): int
    {
        $target = DB::connection();
        if ($target->getDriverName() !== 'pgsql') {
            $this->error('The default connection must be the Postgres database to copy into.');

            return self::FAILURE;
        }
        if (! extension_loaded('pdo_mysql')) {
            $this->error('pdo_mysql is not loaded; it is needed to read the MariaDB database.');

            return self::FAILURE;
        }
        if (! $this->option('source-database') || ! $this->option('source-username')) {
            $this->error('--source-database and --source-username are required.');

            return self::FAILURE;
        }

        $source = $this->connectSource();

        try {
            $tables = $this->tablesToCopy($source, $target);
            $this->assertTargetIsEmpty($target, $tables);

            // Every source read below sees this one snapshot.
            $source->unprepared('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            $counts = [];
            foreach ($tables as $table) {
                $counts[$table] = (int) $source->table($table)->count();
            }

            $this->table(['table', 'rows'], array_map(fn (string $t): array => [$t, number_format($counts[$t])], $tables));
            $this->line(sprintf(
                'From MariaDB %s@%s:%s/%s into Postgres %s.',
                $this->option('source-username'),
                $this->option('source-host'),
                $this->option('source-port'),
                $this->option('source-database'),
                $target->getDatabaseName(),
            ));
            if (! $this->option('force') && ! confirm('Copy these tables?', default: false)) {
                $this->info('Nothing copied.');

                return self::SUCCESS;
            }

            $start = microtime(true);
            $target->transaction(function () use ($source, $target, $tables, $counts): void {
                foreach ($tables as $table) {
                    $this->copyTable($source, $target, $table, $counts[$table]);
                }

                $mismatches = [];
                foreach ($tables as $table) {
                    $copied = (int) $target->table($table)->count();
                    if ($copied !== $counts[$table]) {
                        $mismatches[] = "$table: {$counts[$table]} in MariaDB, $copied copied";
                    }
                }
                if ($mismatches !== []) {
                    throw new RuntimeException('row counts differ, nothing was kept — '.implode('; ', $mismatches));
                }
            });
            $source->unprepared('COMMIT');
        } catch (Throwable $e) {
            $this->error('Copy failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Copied %s rows in %d tables in %.1fs; every row count matches.',
            number_format(array_sum($counts)),
            count($tables),
            microtime(true) - $start,
        ));

        return self::SUCCESS;
    }

    /**
     * The tables both databases have, minus {@see SKIPPED_TABLES}, ordered
     * so that every table comes after the tables its foreign keys point at.
     *
     * @return list<string>
     */
    private function tablesToCopy(Connection $source, Connection $target): array
    {
        $sourceTables = array_column($source->getSchemaBuilder()->getTables($source->getDatabaseName()), 'name');
        $targetTables = array_column($target->select('SELECT tablename AS name FROM pg_tables WHERE schemaname = current_schema()'), 'name');

        $sourceTables = array_values(array_diff($sourceTables, self::SKIPPED_TABLES));
        $targetTables = array_values(array_diff($targetTables, self::SKIPPED_TABLES));

        $onlySource = array_diff($sourceTables, $targetTables);
        $onlyTarget = array_diff($targetTables, $sourceTables);
        if ($onlySource !== [] || $onlyTarget !== []) {
            throw new RuntimeException('the databases have different tables — only in MariaDB: ['
                .implode(', ', $onlySource).'], only in Postgres: ['.implode(', ', $onlyTarget).']. Migrate both to the same state first.');
        }

        foreach ($targetTables as $table) {
            $sourceColumns = $source->getSchemaBuilder()->getColumnListing($table);
            $targetColumns = $target->getSchemaBuilder()->getColumnListing($table);
            sort($sourceColumns);
            sort($targetColumns);
            if ($sourceColumns !== $targetColumns) {
                throw new RuntimeException("the columns of $table differ — MariaDB: [".implode(', ', $sourceColumns)
                    .'], Postgres: ['.implode(', ', $targetColumns).']. Migrate both to the same state first.');
            }
        }

        $edges = array_map(
            fn (object $fk): array => [$fk->child, $fk->parent],
            $target->select("
                SELECT DISTINCT child.relname AS child, parent.relname AS parent
                FROM pg_constraint con
                JOIN pg_class child ON child.oid = con.conrelid
                JOIN pg_class parent ON parent.oid = con.confrelid
                WHERE con.contype = 'f' AND child.relnamespace = current_schema()::regnamespace
            "),
        );

        return self::inDependencyOrder($targetTables, $edges);
    }

    /**
     * Order tables so that each comes after every table it references;
     * ties are broken alphabetically, so the order is the same every run.
     * References to tables outside the list and to the table itself are
     * ignored.
     *
     * @param  list<string>  $tables
     * @param  list<array{0: string, 1: string}>  $references  [child, parent] pairs.
     * @return list<string>
     *
     * @throws RuntimeException When the references form a cycle.
     */
    public static function inDependencyOrder(array $tables, array $references): array
    {
        $pending = array_fill_keys($tables, []);
        foreach ($references as [$child, $parent]) {
            if ($child !== $parent && isset($pending[$child], $pending[$parent])) {
                $pending[$child][$parent] = true;
            }
        }

        $ordered = [];
        while ($pending !== []) {
            $ready = array_keys(array_filter($pending, fn (array $parents): bool => $parents === []));
            if ($ready === []) {
                throw new RuntimeException('foreign keys form a cycle between: '.implode(', ', array_keys($pending)));
            }
            sort($ready);
            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($pending[$table]);
                foreach ($pending as $child => $parents) {
                    unset($pending[$child][$table]);
                }
            }
        }

        return $ordered;
    }

    /**
     * Convert one MariaDB row for Postgres. Booleans are the only values
     * that need it: MariaDB keeps them as 0/1 integers.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $booleanColumns
     * @return array<string, mixed>
     */
    public static function convertRow(array $row, array $booleanColumns): array
    {
        foreach ($booleanColumns as $column => $_) {
            if ($row[$column] !== null) {
                $row[$column] = (bool) $row[$column];
            }
        }

        return $row;
    }

    /** @param  list<string>  $tables */
    private function assertTargetIsEmpty(Connection $target, array $tables): void
    {
        $filled = array_filter($tables, fn (string $table): bool => $target->table($table)->exists());
        if ($filled !== []) {
            throw new RuntimeException('the Postgres database already holds data in: '.implode(', ', $filled)
                .'. Copy only into a freshly migrated database (`php artisan migrate:fresh`).');
        }
    }

    private function copyTable(Connection $source, Connection $target, string $table, int $expected): void
    {
        $start = microtime(true);
        $columns = $target->getSchemaBuilder()->getColumns($table);
        $booleanColumns = [];
        foreach ($columns as $column) {
            if ($column['type_name'] === 'bool') {
                $booleanColumns[$column['name']] = true;
            }
        }
        $perInsert = max(1, intdiv(self::VALUES_PER_INSERT, count($columns)));

        $batch = [];
        $copied = 0;
        foreach ($source->table($table)->cursor() as $row) {
            $batch[] = self::convertRow((array) $row, $booleanColumns);
            if (count($batch) === $perInsert) {
                $target->table($table)->insert($batch);
                $copied += count($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $target->table($table)->insert($batch);
            $copied += count($batch);
        }

        $this->line(sprintf('  %-32s %10s of %10s rows  %6.1fs', $table, number_format($copied), number_format($expected), microtime(true) - $start));
    }

    /**
     * A connection to the source, with unbuffered reads so a cursor over a
     * large table streams instead of loading the whole result into memory.
     */
    private function connectSource(): Connection
    {
        $password = $this->option('source-password-stdin')
            ? rtrim((string) stream_get_contents(STDIN), "\r\n")
            : password('MariaDB password');

        config(['database.connections.'.self::SOURCE => [
            'driver' => 'mariadb',
            'host' => $this->option('source-host'),
            'port' => $this->option('source-port'),
            'database' => $this->option('source-database'),
            'username' => $this->option('source-username'),
            'password' => $password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'options' => [Mysql::ATTR_USE_BUFFERED_QUERY => false],
        ]]);

        return DB::connection(self::SOURCE);
    }
}
