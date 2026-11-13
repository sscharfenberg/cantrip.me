<?php

namespace Tests\Unit\Services\Scryfall\Shadow;

use App\Services\Scryfall\Shadow\ShadowSwapper;
use App\Services\Scryfall\Shadow\ShadowTableService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The order of operations in {@see ShadowSwapper}, against a recording
 * stand-in for {@see ShadowTableService} — the real one is MariaDB-only.
 *
 * What is pinned is the guarantee the class exists for: once FK
 * constraints have been dropped, they are put back whatever fails, and
 * the on-disk record of them is only cleared once they are.
 */
class ShadowSwapperTest extends TestCase
{
    private RecordingShadowTables $tables;

    /** @var list<string> */
    private array $steps = [];

    private bool $failBeforeRename = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tables = new RecordingShadowTables;
    }

    #[Test]
    public function the_constraints_are_re_added_before_the_retired_tables_go(): void
    {
        $count = $this->swap();

        $this->assertSame(2, $count);
        $this->assertSame(
            ['capture', 'remember', 'drop', 'swap', 'add', 'forget', 'dropRetired'],
            $this->tables->calls,
        );
        $this->assertSame(
            ['captureForeignKeys', 'dropForeignKeys', 'validateSchema', 'swap', 'addForeignKeys', 'dropRetired'],
            $this->steps,
            'each step runs through the alerting wrapper under its own name',
        );
    }

    #[Test]
    public function a_failed_check_before_the_rename_stops_the_swap_and_re_adds_the_constraints(): void
    {
        $this->failBeforeRename = true;

        $this->expectSwapToFail('schema drifted');

        $this->assertSame(['capture', 'remember', 'drop', 'add', 'forget'], $this->tables->calls);
        $this->assertSame(['captureForeignKeys', 'dropForeignKeys', 'validateSchema', 'addForeignKeys'], $this->steps);
    }

    #[Test]
    public function a_failed_swap_still_re_adds_the_constraints_to_the_old_tables(): void
    {
        $this->tables->failOn = 'swap';

        $this->expectSwapToFail('swap failed');

        $this->assertSame(['capture', 'remember', 'drop', 'swap', 'add', 'forget'], $this->tables->calls);
    }

    #[Test]
    public function a_drop_that_breaks_off_halfway_still_re_adds_the_constraints(): void
    {
        $this->tables->failOn = 'drop';

        $this->expectSwapToFail('drop failed');

        $this->assertSame(['capture', 'remember', 'drop', 'add', 'forget'], $this->tables->calls);
    }

    #[Test]
    public function a_failed_re_add_keeps_the_record_for_the_next_run(): void
    {
        $this->tables->failOn = 'add';

        $this->expectSwapToFail('add failed');

        $this->assertSame(['capture', 'remember', 'drop', 'swap', 'add'], $this->tables->calls);
        $this->assertNotContains('forget', $this->tables->calls);
    }

    #[Test]
    public function nothing_is_dropped_when_the_record_cannot_be_written(): void
    {
        $this->tables->failOn = 'remember';

        $this->expectSwapToFail('remember failed');

        $this->assertSame(['capture', 'remember'], $this->tables->calls);
    }

    private function swap(): int
    {
        return (new ShadowSwapper($this->tables))->swapIntoLive(
            function (string $name, callable $work): void {
                $this->steps[] = $name;
                $work();
            },
            function (): void {
                if ($this->failBeforeRename) {
                    throw new RuntimeException('schema drifted');
                }
            },
        );
    }

    private function expectSwapToFail(string $message): void
    {
        try {
            $this->swap();
            $this->fail('the swap was expected to fail');
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}

/**
 * Records which {@see ShadowTableService} operations ran, in order, and
 * fails the one named in `$failOn`.
 */
class RecordingShadowTables extends ShadowTableService
{
    /** @var list<string> */
    public array $calls = [];

    public ?string $failOn = null;

    public function captureForeignKeys(array $tables = []): array
    {
        $this->record('capture');

        return [(object) ['CONSTRAINT_NAME' => 'a'], (object) ['CONSTRAINT_NAME' => 'b']];
    }

    public function rememberForeignKeys(array $fks): void
    {
        $this->record('remember');
    }

    public function dropForeignKeys(array $fks): void
    {
        $this->record('drop');
    }

    public function swap(array $tables = []): void
    {
        $this->record('swap');
    }

    public function addForeignKeys(array $fks): int
    {
        $this->record('add');

        return count($fks);
    }

    public function forgetForeignKeys(): void
    {
        $this->record('forget');
    }

    public function dropRetired(array $tables = []): void
    {
        $this->record('dropRetired');
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
        if ($this->failOn === $call) {
            throw new RuntimeException("$call failed");
        }
    }
}
