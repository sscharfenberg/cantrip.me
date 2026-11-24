<?php

/** @noinspection SqlNoDataSourceInspection */

namespace Tests\Feature\Services\Shadow;

use App\Services\Scryfall\Shadow\ShadowSwapper;
use App\Services\Scryfall\Shadow\ShadowTableRegistry;
use App\Services\Scryfall\Shadow\ShadowTableService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for {@see ShadowTableService}. Postgres-only —
 * `CREATE TABLE … (LIKE …)`, the catalog queries and transactional DDL have
 * no SQLite equivalent.
 *
 * Tests use throwaway tables (prefix `_shadow_test_*`) instead of touching
 * the real scryfall tables. The one exception is {@see cleanup_drops_only_known_scryfall_shadows}
 * which exercises the registry-driven cleanup against real table names —
 * idempotent and safe even when no shadows exist.
 *
 * Locally: `npm run e2e:db:up`, then
 * `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5499 DB_DATABASE=cantrip_e2e DB_USERNAME=cantrip_e2e DB_PASSWORD=cantrip_e2e php artisan test --testsuite=Staging --filter=Shadow`.
 */
class ShadowTableServiceTest extends TestCase
{
    private ShadowTableService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'ShadowTableService requires Postgres (CREATE TABLE … LIKE, transactional renames). '
                .'Run on staging with composer test:pgsql.'
            );
        }

        $this->service = new ShadowTableService;
        $this->dropThrowawayTables();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->dropThrowawayTables();
        }
        parent::tearDown();
    }

    #[Test]
    public function wait_for_disk_on_commit_switches_this_session_off_and_back_to_the_server_default(): void
    {
        $serverDefault = DB::scalar('SHOW synchronous_commit');
        $this->assertNotSame('off', $serverDefault, 'the fixture needs a server that waits for the disk by default');

        try {
            $this->service->waitForDiskOnCommit(false);
            $this->assertSame('off', DB::scalar('SHOW synchronous_commit'));

            $this->service->waitForDiskOnCommit(true);
            $this->assertSame($serverDefault, DB::scalar('SHOW synchronous_commit'));
        } finally {
            DB::statement('RESET synchronous_commit');
        }
    }

    #[Test]
    public function create_like_copies_schema_without_data(): void
    {
        DB::statement('CREATE TABLE _shadow_test_a (id INT PRIMARY KEY, label VARCHAR(32))');
        DB::table('_shadow_test_a')->insert([
            ['id' => 1, 'label' => 'live'],
            ['id' => 2, 'label' => 'live'],
        ]);

        $this->service->createLike('_shadow_test_a');

        $this->assertSame(0, DB::table('_shadow_test_a__shadow')->count());
        // Schema match: should accept the same insert shape.
        DB::table('_shadow_test_a__shadow')->insert(['id' => 99, 'label' => 'shadowed']);
        $this->assertSame(1, DB::table('_shadow_test_a__shadow')->count());
    }

    #[Test]
    public function create_like_drops_pre_existing_shadow(): void
    {
        DB::statement('CREATE TABLE _shadow_test_a (id INT PRIMARY KEY)');
        DB::statement('CREATE TABLE _shadow_test_a__shadow (id INT PRIMARY KEY)');
        DB::table('_shadow_test_a__shadow')->insert(['id' => 42]);

        $this->service->createLike('_shadow_test_a');

        // Prior shadow data is gone — recreated empty.
        $this->assertSame(0, DB::table('_shadow_test_a__shadow')->count());
    }

    #[Test]
    public function swap_puts_every_shadow_live_and_drops_the_old_tables(): void
    {
        DB::statement('CREATE TABLE _shadow_test_a (id INT PRIMARY KEY, label VARCHAR(32))');
        DB::statement('CREATE TABLE _shadow_test_b (id INT PRIMARY KEY, label VARCHAR(32))');
        DB::table('_shadow_test_a')->insert(['id' => 1, 'label' => 'old-a']);
        DB::table('_shadow_test_b')->insert(['id' => 1, 'label' => 'old-b']);

        $this->service->createLike('_shadow_test_a');
        $this->service->createLike('_shadow_test_b');
        DB::table('_shadow_test_a__shadow')->insert(['id' => 2, 'label' => 'new-a']);
        DB::table('_shadow_test_b__shadow')->insert(['id' => 2, 'label' => 'new-b']);

        $this->service->transaction(fn () => $this->service->swap(['_shadow_test_a', '_shadow_test_b']));

        $this->assertSame(['new-a'], DB::table('_shadow_test_a')->pluck('label')->all());
        $this->assertSame(['new-b'], DB::table('_shadow_test_b')->pluck('label')->all());
        foreach (['_shadow_test_a__shadow', '_shadow_test_a__retired', '_shadow_test_b__shadow', '_shadow_test_b__retired'] as $gone) {
            $this->assertFalse($this->tableExists($gone), "$gone must not survive the swap.");
        }
    }

    /**
     * Index names are unique per schema, so the shadow's copies are created
     * under names of their own; the swap must hand the live names back, or
     * a migration that drops an index by its Laravel name fails later.
     */
    #[Test]
    public function swap_gives_the_new_table_the_live_index_names_back(): void
    {
        DB::statement('CREATE TABLE _shadow_test_a (id INT, code VARCHAR(8), label VARCHAR(32))');
        DB::statement('ALTER TABLE _shadow_test_a ADD CONSTRAINT _shadow_test_a_primary PRIMARY KEY (id)');
        DB::statement('ALTER TABLE _shadow_test_a ADD CONSTRAINT _shadow_test_a_code_unique UNIQUE (code)');
        DB::statement('CREATE INDEX _shadow_test_a_label_index ON _shadow_test_a (label)');
        $before = $this->indexNames('_shadow_test_a');

        foreach ([1, 2] as $run) {
            $this->service->createLike('_shadow_test_a');

            $this->service->transaction(fn () => $this->service->swap(['_shadow_test_a']));

            $this->assertSame($before, $this->indexNames('_shadow_test_a'), "run $run must restore the index names.");
        }
        $this->assertSame(
            ['_shadow_test_a_code_unique', '_shadow_test_a_primary'],
            DB::table('pg_constraint')->where('conrelid', DB::raw("'_shadow_test_a'::regclass"))->orderBy('conname')->pluck('conname')->all(),
            'the constraints behind the unique and primary indexes are renamed with them.',
        );
    }

    #[Test]
    public function capture_drop_and_readd_foreign_keys_round_trip(): void
    {
        $captured = $this->createThrowawayForeignKey();

        $this->assertCount(1, $captured, 'capture must find the throwaway FK.');
        $this->assertSame('_shadow_test_b', $captured[0]->table_name);
        $this->assertSame('_shadow_test_b_fk', $captured[0]->name);
        $this->assertSame('_shadow_test_a', $captured[0]->referenced_table);
        $this->assertSame('FOREIGN KEY (parent_id) REFERENCES _shadow_test_a(id) ON DELETE CASCADE', $captured[0]->definition);

        $this->service->dropForeignKeys($captured);
        $this->assertSame(0, $this->foreignKeyCount(), 'drop must remove the FK.');

        $this->assertSame(1, $this->service->addForeignKeys($captured));
        $this->assertSame(1, $this->foreignKeyCount(), 'addForeignKeys must restore the FK with its original reference.');
    }

    /**
     * The sequence {@see ShadowSwapper} runs:
     * afterwards the FK points at the new live parent, not at the dropped
     * old one.
     */
    #[Test]
    public function after_the_swap_the_foreign_key_points_at_the_new_table(): void
    {
        $captured = $this->createThrowawayForeignKey();
        DB::table('_shadow_test_a')->insert(['id' => 1]);
        DB::table('_shadow_test_b')->insert(['id' => 1, 'parent_id' => 1]);
        $this->service->createLike('_shadow_test_a');
        DB::table('_shadow_test_a__shadow')->insert([['id' => 1], ['id' => 2]]);

        $this->service->transaction(function () use ($captured): void {
            $this->service->dropForeignKeys($captured);
            $this->service->swap(['_shadow_test_a']);
            $this->service->addForeignKeys($captured);
        });

        DB::table('_shadow_test_b')->insert(['id' => 2, 'parent_id' => 2]);
        $this->assertSame(2, DB::table('_shadow_test_b')->count(), 'a child of a parent only the new table has is accepted.');
        $this->expectException(QueryException::class);
        DB::table('_shadow_test_b')->insert(['id' => 3, 'parent_id' => 3]);
    }

    /**
     * The guarantee the transaction exists for: a constraint the new data
     * cannot satisfy undoes the whole swap, the dropped constraint included.
     */
    #[Test]
    public function a_failed_re_add_rolls_the_whole_swap_back(): void
    {
        $captured = $this->createThrowawayForeignKey();
        DB::table('_shadow_test_a')->insert(['id' => 1]);
        DB::table('_shadow_test_b')->insert(['id' => 1, 'parent_id' => 1]);
        $this->service->createLike('_shadow_test_a');
        DB::table('_shadow_test_a__shadow')->insert(['id' => 2]);

        try {
            $this->service->transaction(function () use ($captured): void {
                $this->service->dropForeignKeys($captured);
                $this->service->swap(['_shadow_test_a']);
                $this->service->addForeignKeys($captured);
            });
            $this->fail('re-adding the FK over an orphaned child was expected to fail.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode(), 'foreign_key_violation');
        }

        $this->assertSame([1], DB::table('_shadow_test_a')->pluck('id')->all(), 'live keeps its old rows.');
        $this->assertSame([2], DB::table('_shadow_test_a__shadow')->pluck('id')->all(), 'the shadow is still there.');
        $this->assertSame(1, $this->foreignKeyCount(), 'the dropped FK is back.');
    }

    #[Test]
    public function cleanup_drops_only_known_scryfall_shadows(): void
    {
        $sample = ShadowTableRegistry::TABLES[0];
        $shadow = ShadowTableRegistry::shadow($sample);
        $retired = ShadowTableRegistry::retired($sample);

        DB::statement("CREATE TABLE \"$shadow\" (id INT PRIMARY KEY)");
        DB::statement("CREATE TABLE \"$retired\" (id INT PRIMARY KEY)");
        DB::statement('CREATE TABLE _shadow_test_a__shadow (id INT PRIMARY KEY)');

        $this->service->cleanup();

        $this->assertFalse($this->tableExists($shadow), 'registered shadow must be cleaned.');
        $this->assertFalse($this->tableExists($retired), 'registered retired must be cleaned.');
        $this->assertTrue(
            $this->tableExists('_shadow_test_a__shadow'),
            'cleanup must only touch registered scryfall tables.',
        );
    }

    private function dropThrowawayTables(): void
    {
        $names = [
            '_shadow_test_b__shadow', '_shadow_test_b__retired', '_shadow_test_b',
            '_shadow_test_a', '_shadow_test_a__shadow', '_shadow_test_a__retired',
        ];
        foreach ($names as $name) {
            DB::statement("DROP TABLE IF EXISTS \"$name\"");
        }
        // Also clean up registered shadows in case a prior run aborted
        // mid-test and left them on disk.
        $sample = ShadowTableRegistry::TABLES[0];
        DB::statement('DROP TABLE IF EXISTS "'.ShadowTableRegistry::shadow($sample).'"');
        DB::statement('DROP TABLE IF EXISTS "'.ShadowTableRegistry::retired($sample).'"');
    }

    /**
     * A throwaway parent + child with one FK between them, captured the
     * way the orchestrator captures the real ones.
     *
     * @return array<int, object>
     */
    private function createThrowawayForeignKey(): array
    {
        DB::statement('CREATE TABLE _shadow_test_a (id INT PRIMARY KEY)');
        DB::statement('CREATE TABLE _shadow_test_b (id INT PRIMARY KEY, parent_id INT, '
            .'CONSTRAINT _shadow_test_b_fk FOREIGN KEY (parent_id) REFERENCES _shadow_test_a (id) ON DELETE CASCADE)');

        return $this->service->captureForeignKeys(['_shadow_test_a']);
    }

    /** FKs named `_shadow_test_b_fk` on `_shadow_test_b` that reference the table now named `_shadow_test_a`. */
    private function foreignKeyCount(): int
    {
        return DB::table('pg_constraint')
            ->where('conname', '_shadow_test_b_fk')
            ->where('conrelid', DB::raw("'_shadow_test_b'::regclass"))
            ->where('confrelid', DB::raw("'_shadow_test_a'::regclass"))
            ->count();
    }

    /** @return list<string> */
    private function indexNames(string $table): array
    {
        return DB::table('pg_indexes')->where('tablename', $table)->orderBy('indexname')->pluck('indexname')->all();
    }

    private function tableExists(string $name): bool
    {
        return DB::getSchemaBuilder()->hasTable($name);
    }
}
