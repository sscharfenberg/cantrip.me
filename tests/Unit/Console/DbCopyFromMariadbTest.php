<?php

namespace Tests\Unit\Console;

use App\Console\Commands\DbCopyFromMariadb;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The two pure parts of {@see DbCopyFromMariadb}: the order tables are
 * written in, which must put every referenced table first or Postgres
 * rejects the child rows, and the conversion of MariaDB's 0/1 booleans.
 * The copy itself needs both databases and is rehearsed against staging.
 */
class DbCopyFromMariadbTest extends TestCase
{
    #[Test]
    public function referenced_tables_come_first(): void
    {
        $order = DbCopyFromMariadb::inDependencyOrder(
            ['deck_cards', 'decks', 'users', 'containers', 'deck_categories', 'default_cards', 'sets'],
            [
                ['deck_cards', 'decks'],
                ['deck_cards', 'deck_categories'],
                ['deck_cards', 'default_cards'],
                ['deck_categories', 'decks'],
                ['decks', 'users'],
                ['decks', 'containers'],
                ['containers', 'users'],
                ['containers', 'default_cards'],
                ['default_cards', 'sets'],
            ],
        );

        $this->assertSame(['sets', 'users', 'default_cards', 'containers', 'decks', 'deck_categories', 'deck_cards'], $order);
    }

    #[Test]
    public function references_outside_the_list_and_to_the_table_itself_are_ignored(): void
    {
        $order = DbCopyFromMariadb::inDependencyOrder(
            ['b', 'a'],
            [['a', 'a'], ['b', 'sessions'], ['sessions', 'a']],
        );

        $this->assertSame(['a', 'b'], $order);
    }

    #[Test]
    public function a_cycle_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('foreign keys form a cycle between: a, b');

        DbCopyFromMariadb::inDependencyOrder(['a', 'b', 'c'], [['a', 'b'], ['b', 'a']]);
    }

    #[Test]
    public function boolean_columns_become_booleans_and_nothing_else_changes(): void
    {
        $row = DbCopyFromMariadb::convertRow(
            ['id' => 'x', 'proxy' => 1, 'digital' => 0, 'reserved' => null, 'amount' => 1],
            ['proxy' => true, 'digital' => true, 'reserved' => true],
        );

        $this->assertSame(['id' => 'x', 'proxy' => true, 'digital' => false, 'reserved' => null, 'amount' => 1], $row);
    }
}
