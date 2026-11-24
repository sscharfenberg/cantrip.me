<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Postgres creates no index for a foreign key (MariaDB did, silently), so
 * an FK column without an explicit index makes every lookup through it —
 * all printings of an oracle card, all stacks in a container — read the
 * whole table, and every delete of a parent row scan the child table.
 * Schema introspection is portable, so this checks the migrated schema on
 * SQLite: each foreign key's columns must lead some index of its table.
 */
class ForeignKeyIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_foreign_key_is_backed_by_an_index(): void
    {
        $unindexed = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $indexes = Schema::getIndexes($table);

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $columns = $foreignKey['columns'];
                $backed = collect($indexes)->contains(
                    fn (array $index): bool => array_slice($index['columns'], 0, count($columns)) === $columns
                );

                if (! $backed) {
                    $unindexed[] = "$table(".implode(', ', $columns).") → {$foreignKey['foreign_table']}";
                }
            }
        }

        $this->assertSame([], $unindexed, 'Foreign keys without an index: '.implode('; ', $unindexed));
    }
}
