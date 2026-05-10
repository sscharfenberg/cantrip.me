<?php

/** @noinspection SqlNoDataSourceInspection */

namespace Tests\Feature\Services\Shadow;

use App\Services\Scryfall\Shadow\ShadowValidationService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for {@see ShadowValidationService}. MariaDB-only —
 * the validator builds LEFT JOIN queries on real shadow tables.
 *
 * Tests use throwaway tables (`_validation_test_*`) and pass a custom
 * check set so we don't depend on the registry or the real scryfall data.
 */
class ShadowValidationServiceTest extends TestCase
{
    private ShadowValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'ShadowValidationService requires MariaDB. Run on staging with composer test:mysql.'
            );
        }

        $this->service = new ShadowValidationService;
        $this->dropThrowawayTables();
        // Two table pairs: a "scryfall-style" parent (shadow build) and a
        // "user-data" child (live, references the shadow).
        DB::statement('CREATE TABLE _validation_test_parent__shadow (id INT UNSIGNED PRIMARY KEY)');
        DB::statement('CREATE TABLE _validation_test_child (id INT UNSIGNED PRIMARY KEY, parent_id INT UNSIGNED NULL)');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropThrowawayTables();
        }
        parent::tearDown();
    }

    #[Test]
    public function returns_empty_when_every_fk_resolves(): void
    {
        DB::table('_validation_test_parent__shadow')->insert([
            ['id' => 1], ['id' => 2], ['id' => 3],
        ]);
        DB::table('_validation_test_child')->insert([
            ['id' => 1, 'parent_id' => 1],
            ['id' => 2, 'parent_id' => 2],
        ]);

        $orphans = $this->service->findOrphans([
            ['_validation_test_child', 'parent_id', '_validation_test_parent', 'live'],
        ]);

        $this->assertSame([], $orphans);
    }

    #[Test]
    public function returns_a_violation_when_a_user_data_row_points_at_a_missing_shadow_row(): void
    {
        DB::table('_validation_test_parent__shadow')->insert([['id' => 1]]);
        DB::table('_validation_test_child')->insert([
            ['id' => 1, 'parent_id' => 1],     // matches
            ['id' => 2, 'parent_id' => 99],    // orphan
            ['id' => 3, 'parent_id' => 100],   // orphan
        ]);

        $orphans = $this->service->findOrphans([
            ['_validation_test_child', 'parent_id', '_validation_test_parent', 'live'],
        ]);

        $this->assertCount(1, $orphans);
        $this->assertSame('_validation_test_child', $orphans[0]['source']);
        $this->assertSame('parent_id', $orphans[0]['fk']);
        $this->assertSame('_validation_test_parent__shadow', $orphans[0]['target']);
        $this->assertSame(2, $orphans[0]['orphans']);
    }

    #[Test]
    public function null_fk_values_are_not_orphans(): void
    {
        DB::table('_validation_test_parent__shadow')->insert([['id' => 1]]);
        DB::table('_validation_test_child')->insert([
            ['id' => 1, 'parent_id' => null],
            ['id' => 2, 'parent_id' => null],
        ]);

        $orphans = $this->service->findOrphans([
            ['_validation_test_child', 'parent_id', '_validation_test_parent', 'live'],
        ]);

        $this->assertSame([], $orphans);
    }

    #[Test]
    public function shadow_mode_resolves_source_to_the_shadow_table(): void
    {
        // Reuse the parent shadow as the *source* under shadow mode and
        // a sibling shadow as the target — proves the resolver picks
        // <source>__shadow when mode is 'shadow'.
        DB::statement('CREATE TABLE _validation_test_target__shadow (id INT UNSIGNED PRIMARY KEY)');
        DB::table('_validation_test_target__shadow')->insert([['id' => 1]]);
        DB::table('_validation_test_parent__shadow')->insert([
            ['id' => 1],
            ['id' => 2],
        ]);
        // Add an FK column on the parent shadow pointing at the target shadow.
        DB::statement('ALTER TABLE _validation_test_parent__shadow ADD COLUMN target_id INT UNSIGNED NULL');
        DB::statement('UPDATE _validation_test_parent__shadow SET target_id = 99 WHERE id = 1');
        DB::statement('UPDATE _validation_test_parent__shadow SET target_id = 1 WHERE id = 2');

        $orphans = $this->service->findOrphans([
            ['_validation_test_parent', 'target_id', '_validation_test_target', 'shadow'],
        ]);

        $this->assertCount(1, $orphans);
        $this->assertSame('_validation_test_parent__shadow', $orphans[0]['source']);
        $this->assertSame(1, $orphans[0]['orphans']);
    }

    private function dropThrowawayTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ([
                '_validation_test_child',
                '_validation_test_parent__shadow',
                '_validation_test_target__shadow',
            ] as $name) {
                DB::statement("DROP TABLE IF EXISTS `$name`");
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
