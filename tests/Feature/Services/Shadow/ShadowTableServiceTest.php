<?php

/** @noinspection SqlNoDataSourceInspection */

namespace Tests\Feature\Services\Shadow;

use App\Services\Scryfall\Shadow\ShadowTableRegistry;
use App\Services\Scryfall\Shadow\ShadowTableService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for {@see ShadowTableService}. MariaDB-only —
 * `CREATE TABLE LIKE`, multi-table `RENAME`, and `SET FOREIGN_KEY_CHECKS`
 * are all MySQL/MariaDB syntax and have no SQLite equivalent.
 *
 * Tests use throwaway tables (prefix `_shadow_test_*`) instead of touching
 * the real scryfall tables. The one exception is {@see cleanup_drops_only_known_scryfall_shadows}
 * which exercises the registry-driven cleanup against real table names —
 * idempotent and safe even when no shadows exist.
 */
class ShadowTableServiceTest extends TestCase
{
    private ShadowTableService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'ShadowTableService requires MariaDB (CREATE TABLE LIKE, multi-table RENAME). '
                .'Run on staging with composer test:mysql.'
            );
        }

        $this->service = new ShadowTableService;
        $this->dropThrowawayTables();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropThrowawayTables();
        }
        parent::tearDown();
    }

    #[Test]
    public function create_like_copies_schema_without_data(): void
    {
        DB::statement('CREATE TABLE _shadow_test_a (id INT UNSIGNED PRIMARY KEY, label VARCHAR(32))');
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
        DB::statement('CREATE TABLE _shadow_test_a (id INT UNSIGNED PRIMARY KEY)');
        DB::statement('CREATE TABLE _shadow_test_a__shadow (id INT UNSIGNED PRIMARY KEY)');
        DB::table('_shadow_test_a__shadow')->insert(['id' => 42]);

        $this->service->createLike('_shadow_test_a');

        // Prior shadow data is gone — recreated empty.
        $this->assertSame(0, DB::table('_shadow_test_a__shadow')->count());
    }

    #[Test]
    public function swap_rotates_live_to_retired_and_shadow_to_live_atomically(): void
    {
        DB::statement('CREATE TABLE _shadow_test_a (id INT UNSIGNED PRIMARY KEY, label VARCHAR(32))');
        DB::statement('CREATE TABLE _shadow_test_b (id INT UNSIGNED PRIMARY KEY, label VARCHAR(32))');
        DB::table('_shadow_test_a')->insert(['id' => 1, 'label' => 'old-a']);
        DB::table('_shadow_test_b')->insert(['id' => 1, 'label' => 'old-b']);

        $this->service->createLike('_shadow_test_a');
        $this->service->createLike('_shadow_test_b');
        DB::table('_shadow_test_a__shadow')->insert(['id' => 2, 'label' => 'new-a']);
        DB::table('_shadow_test_b__shadow')->insert(['id' => 2, 'label' => 'new-b']);

        $this->service->swap(['_shadow_test_a', '_shadow_test_b']);

        $this->assertSame('new-a', DB::table('_shadow_test_a')->value('label'));
        $this->assertSame('new-b', DB::table('_shadow_test_b')->value('label'));
        $this->assertSame('old-a', DB::table('_shadow_test_a__retired')->value('label'));
        $this->assertSame('old-b', DB::table('_shadow_test_b__retired')->value('label'));
    }

    #[Test]
    public function drop_retired_removes_only_retired_tables(): void
    {
        DB::statement('CREATE TABLE _shadow_test_a (id INT UNSIGNED PRIMARY KEY)');
        DB::statement('CREATE TABLE _shadow_test_a__retired (id INT UNSIGNED PRIMARY KEY)');

        $this->service->dropRetired(['_shadow_test_a']);

        $this->assertTrue($this->tableExists('_shadow_test_a'), 'live table must remain.');
        $this->assertFalse($this->tableExists('_shadow_test_a__retired'), 'retired table must be dropped.');
    }

    #[Test]
    public function capture_drop_and_readd_foreign_keys_round_trip(): void
    {
        // Throwaway parent + child with an FK between them. Pass the
        // parent name explicitly to captureForeignKeys() so we don't
        // touch the real registered scryfall tables on staging.
        DB::statement('CREATE TABLE _shadow_test_a (id INT UNSIGNED PRIMARY KEY)');
        DB::statement('CREATE TABLE _shadow_test_b (id INT UNSIGNED PRIMARY KEY, parent_id INT UNSIGNED, '
            .'CONSTRAINT _shadow_test_b_fk FOREIGN KEY (parent_id) REFERENCES _shadow_test_a (id) ON DELETE CASCADE)');

        $captured = $this->service->captureForeignKeys(['_shadow_test_a']);
        $this->assertCount(1, $captured, 'capture must find the throwaway FK.');
        $this->assertSame('_shadow_test_b', $captured[0]->TABLE_NAME);
        $this->assertSame('_shadow_test_b_fk', $captured[0]->CONSTRAINT_NAME);
        $this->assertSame('_shadow_test_a', $captured[0]->REFERENCED_TABLE_NAME);
        $this->assertSame('CASCADE', $captured[0]->DELETE_RULE);

        $this->service->dropForeignKeys($captured);
        $afterDrop = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', '_shadow_test_b')
            ->where('CONSTRAINT_NAME', '_shadow_test_b_fk')
            ->count();
        $this->assertSame(0, $afterDrop, 'drop must remove the FK.');

        $this->service->addForeignKeys($captured);
        $afterAdd = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', '_shadow_test_b')
            ->where('CONSTRAINT_NAME', '_shadow_test_b_fk')
            ->where('REFERENCED_TABLE_NAME', '_shadow_test_a')
            ->count();
        $this->assertSame(1, $afterAdd, 'addForeignKeys must restore the FK with original references.');
    }

    #[Test]
    public function cleanup_drops_only_known_scryfall_shadows(): void
    {
        $sample = ShadowTableRegistry::TABLES[0];
        $shadow = ShadowTableRegistry::shadow($sample);
        $retired = ShadowTableRegistry::retired($sample);

        DB::statement("CREATE TABLE `$shadow` (id INT UNSIGNED PRIMARY KEY)");
        DB::statement("CREATE TABLE `$retired` (id INT UNSIGNED PRIMARY KEY)");
        DB::statement('CREATE TABLE _shadow_test_a__shadow (id INT UNSIGNED PRIMARY KEY)');

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
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $names = [
                '_shadow_test_b__shadow', '_shadow_test_b__retired', '_shadow_test_b',
                '_shadow_test_a', '_shadow_test_a__shadow', '_shadow_test_a__retired',
            ];
            foreach ($names as $name) {
                DB::statement("DROP TABLE IF EXISTS `$name`");
            }
            // Also clean up registered shadows in case a prior run aborted
            // mid-test and left them on disk.
            $sample = ShadowTableRegistry::TABLES[0];
            DB::statement('DROP TABLE IF EXISTS `'.ShadowTableRegistry::shadow($sample).'`');
            DB::statement('DROP TABLE IF EXISTS `'.ShadowTableRegistry::retired($sample).'`');
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function tableExists(string $name): bool
    {
        return DB::getSchemaBuilder()->hasTable($name);
    }
}
