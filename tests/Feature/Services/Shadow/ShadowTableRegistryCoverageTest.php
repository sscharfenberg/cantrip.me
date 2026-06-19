<?php

/** @noinspection SqlNoDataSourceInspection */

namespace Tests\Feature\Services\Shadow;

use App\Services\Scryfall\Shadow\ShadowTableRegistry;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schema-driven completeness guard for {@see ShadowTableRegistry::FK_CHECKS}.
 *
 * Every FK that references a swap table from a non-swap (user-data) table is
 * captured and re-added around the atomic RENAME. If such an FK is NOT in the
 * pre-swap orphan check, an orphan slips past validation and instead makes
 * addForeignKeys() throw (errno 1452) AFTER the swap and dropRetired — a far
 * worse partial-state failure than a clean abort.
 *
 * This test derives the real user-data FK set from INFORMATION_SCHEMA and
 * asserts FK_CHECKS covers all of it, so a future migration that adds a new
 * user table referencing scryfall data fails here instead of at 02:00 in prod.
 *
 * MariaDB-only — needs real FK constraints from INFORMATION_SCHEMA, which the
 * SQLite Local suite does not expose. Read-only (INFORMATION_SCHEMA SELECT).
 */
class ShadowTableRegistryCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Needs real FK constraints from INFORMATION_SCHEMA. Run on staging with composer test:mysql.'
            );
        }
    }

    #[Test]
    public function fk_checks_cover_every_user_data_fk_referencing_swap_tables(): void
    {
        $swap = ShadowTableRegistry::TABLES;
        $database = DB::getDatabaseName();
        $placeholders = implode(',', array_fill(0, count($swap), '?'));

        $fks = DB::select(
            "SELECT TABLE_NAME AS source, COLUMN_NAME AS fk, REFERENCED_TABLE_NAME AS target
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND REFERENCED_TABLE_NAME IN ($placeholders)",
            [$database, ...$swap]
        );

        $liveChecks = [];
        foreach (ShadowTableRegistry::FK_CHECKS as [$source, $fk, , $mode]) {
            if ($mode === 'live') {
                $liveChecks["$source.$fk"] = true;
            }
        }

        $missing = [];
        foreach ($fks as $row) {
            // User-data FK = owning table is not itself a swap table.
            if (in_array($row->source, $swap, true)) {
                continue;
            }
            if (! isset($liveChecks["{$row->source}.{$row->fk}"])) {
                $missing[] = "{$row->source}.{$row->fk} -> {$row->target}";
            }
        }

        sort($missing);
        $this->assertSame(
            [],
            $missing,
            "Unvalidated user-data FK(s) into swap tables — add as 'live' entries to ".
            'ShadowTableRegistry::FK_CHECKS: '.implode(', ', $missing)
        );
    }
}
