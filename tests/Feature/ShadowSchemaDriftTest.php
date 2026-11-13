<?php

namespace Tests\Feature;

use App\Services\Scryfall\Shadow\ShadowValidationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see ShadowValidationService::findSchemaDrift()}: a
 * deploy that migrates a swap table while `scryfall:update` is building
 * changes only the live table, and the swap would undo it. Schema
 * introspection is portable, so this runs on SQLite against a throwaway
 * live/shadow pair; the real swap tables were checked on MariaDB 11.8.
 *
 * The Local PHPUnit suite uses SQLite. The defensive `mysql` skip
 * keeps the test out of a misconfigured `composer test:mysql`
 * invocation (which would wipe live data via `RefreshDatabase`).
 */
class ShadowSchemaDriftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }

        foreach (['_drift_test', '_drift_test__shadow'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
                $t->string('name', 64)->index();
            });
        }
    }

    #[Test]
    public function a_shadow_created_like_its_live_table_has_not_drifted(): void
    {
        $this->assertSame([], (new ShadowValidationService)->findSchemaDrift(['_drift_test']));
    }

    #[Test]
    public function a_column_migrated_onto_the_live_table_mid_run_is_drift(): void
    {
        Schema::table('_drift_test', fn (Blueprint $t) => $t->string('added')->nullable());

        $this->assertSame(['_drift_test'], (new ShadowValidationService)->findSchemaDrift(['_drift_test']));
    }

    #[Test]
    public function an_index_migrated_onto_the_live_table_mid_run_is_drift(): void
    {
        Schema::table('_drift_test', fn (Blueprint $t) => $t->unique('name', 'drift_name_unique'));

        $this->assertSame(['_drift_test'], (new ShadowValidationService)->findSchemaDrift(['_drift_test']));
    }
}
