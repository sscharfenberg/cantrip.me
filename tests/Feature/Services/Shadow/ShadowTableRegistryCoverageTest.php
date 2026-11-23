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
 * dropped and re-added around the swap. If such an FK is NOT in the pre-swap
 * orphan check, an orphan slips past validation and instead makes
 * addForeignKeys() fail inside the swap's transaction: the swap rolls back
 * and the alert names a constraint violation instead of the orphaned rows.
 *
 * This test derives the real user-data FK set from the Postgres catalog and
 * asserts FK_CHECKS covers all of it, so a future migration that adds a new
 * user table referencing scryfall data fails here instead of at 04:00 in prod.
 *
 * Postgres-only — needs real FK constraints from the catalog, which the
 * SQLite Local suite does not expose. Read-only.
 */
class ShadowTableRegistryCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'Needs real FK constraints from the Postgres catalog. Run on staging with composer test:pgsql.'
            );
        }
    }

    #[Test]
    public function fk_checks_cover_every_user_data_fk_referencing_swap_tables(): void
    {
        $swap = ShadowTableRegistry::TABLES;
        $placeholders = implode(',', array_fill(0, count($swap), '?'));

        $fks = DB::select(
            "SELECT child.relname AS source, att.attname AS fk, parent.relname AS target
             FROM pg_constraint con
             JOIN pg_class child ON child.oid = con.conrelid
             JOIN pg_class parent ON parent.oid = con.confrelid
             JOIN pg_namespace ns ON ns.oid = parent.relnamespace
             JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY (con.conkey)
             WHERE con.contype = 'f'
               AND ns.nspname = current_schema()
               AND parent.relname IN ($placeholders)",
            $swap
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
