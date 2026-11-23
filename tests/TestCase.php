<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Refuse to let a database-resetting trait near anything but in-memory
     * SQLite.
     *
     * `RefreshDatabase` runs `migrate:fresh` on any connection that is not in
     * memory, which drops every table — the Scryfall dataset and every user's
     * collection and decks alike. This runs before the traits do, so a test
     * run pointed at a real database by mistake (PHPUnit without
     * `--testsuite`, or with the wrong one) skips these tests instead of
     * wiping it. A skip inside a test's own `setUp()` comes too late: by then
     * `parent::setUp()` has already reset the database.
     */
    protected function setUpTraits()
    {
        $uses = $this->traitsUsedByTest ?? class_uses_recursive(static::class);
        $resets = isset($uses[RefreshDatabase::class])
            || isset($uses[DatabaseMigrations::class])
            || isset($uses[DatabaseTruncation::class]);

        if ($resets && ! $this->isInMemorySqlite()) {
            $this->markTestSkipped('Resets the database, so it runs only against in-memory SQLite (`composer test`).');
        }

        return parent::setUpTraits();
    }

    private function isInMemorySqlite(): bool
    {
        $connection = DB::connection();

        return $connection->getDriverName() === 'sqlite'
            && $connection->getConfig('database') === ':memory:';
    }
}
