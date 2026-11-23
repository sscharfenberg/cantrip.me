<?php

namespace Tests\Unit\Services\Scryfall\Shadow;

use App\Services\Scryfall\Shadow\ShadowSwapper;
use App\Services\Scryfall\Shadow\ShadowTableService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The order of operations in {@see ShadowSwapper}, against a recording
 * stand-in for {@see ShadowTableService} — the real one needs Postgres.
 *
 * What is pinned is the guarantee the class exists for: the FK drop, the
 * schema check, the swap and the re-add run in one transaction, so any
 * failure among them rolls the drop back with it — and the alerting
 * wrapper only ever runs outside that transaction, where Postgres still
 * accepts the alert's own writes.
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
    public function the_drop_the_swap_and_the_re_add_commit_together(): void
    {
        $count = $this->swap();

        $this->assertSame(2, $count);
        $this->assertSame(
            ['capture', 'analyze', 'begin', 'drop', 'swap', 'add', 'commit'],
            $this->tables->calls,
        );
        $this->assertSame(['captureForeignKeys', 'analyzeShadows'], $this->steps);
    }

    #[Test]
    public function a_failed_check_before_the_rename_rolls_the_drop_back(): void
    {
        $this->failBeforeRename = true;

        $this->expectSwapToFail('schema drifted');

        $this->assertSame(['capture', 'analyze', 'begin', 'drop', 'rollback'], $this->tables->calls);
        $this->assertSame(['captureForeignKeys', 'analyzeShadows', 'validateSchema'], $this->steps);
    }

    #[Test]
    public function a_failed_swap_rolls_the_drop_back(): void
    {
        $this->tables->failOn = 'swap';

        $this->expectSwapToFail('swap failed');

        $this->assertSame(['capture', 'analyze', 'begin', 'drop', 'swap', 'rollback'], $this->tables->calls);
        $this->assertSame('swap', end($this->steps));
    }

    #[Test]
    public function a_drop_that_breaks_off_halfway_is_rolled_back(): void
    {
        $this->tables->failOn = 'drop';

        $this->expectSwapToFail('drop failed');

        $this->assertSame(['capture', 'analyze', 'begin', 'drop', 'rollback'], $this->tables->calls);
        $this->assertSame('dropForeignKeys', end($this->steps));
    }

    /** A constraint the new dataset violates undoes the whole swap. */
    #[Test]
    public function a_failed_re_add_rolls_the_swap_back(): void
    {
        $this->tables->failOn = 'add';

        $this->expectSwapToFail('add failed');

        $this->assertSame(['capture', 'analyze', 'begin', 'drop', 'swap', 'add', 'rollback'], $this->tables->calls);
        $this->assertSame('addForeignKeys', end($this->steps));
    }

    #[Test]
    public function nothing_is_dropped_when_the_capture_fails(): void
    {
        $this->tables->failOn = 'capture';

        $this->expectSwapToFail('capture failed');

        $this->assertSame(['capture'], $this->tables->calls);
    }

    private function swap(): int
    {
        return (new ShadowSwapper($this->tables))->swapIntoLive(
            function (string $name, callable $work): void {
                $this->assertFalse($this->tables->inTransaction, "the alerting step '$name' ran inside the transaction");
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
 * fails the one named in `$failOn`. Its transaction records `begin`, then
 * `commit` or — when the work throws — `rollback`.
 */
class RecordingShadowTables extends ShadowTableService
{
    /** @var list<string> */
    public array $calls = [];

    public ?string $failOn = null;

    public bool $inTransaction = false;

    public function captureForeignKeys(array $tables = []): array
    {
        $this->record('capture');

        return [(object) ['name' => 'a'], (object) ['name' => 'b']];
    }

    public function analyzeShadows(array $tables = []): void
    {
        $this->record('analyze');
    }

    public function transaction(callable $work): mixed
    {
        $this->calls[] = 'begin';
        $this->inTransaction = true;
        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->calls[] = 'rollback';

            throw $e;
        } finally {
            $this->inTransaction = false;
        }
        $this->calls[] = 'commit';

        return $result;
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

    private function record(string $call): void
    {
        $this->calls[] = $call;
        if ($this->failOn === $call) {
            throw new RuntimeException("$call failed");
        }
    }
}
