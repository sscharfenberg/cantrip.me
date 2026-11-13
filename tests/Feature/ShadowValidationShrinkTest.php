<?php

namespace Tests\Feature;

use App\Services\Scryfall\Shadow\ShadowValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see ShadowValidationService::findShrunkTables()}, the
 * pre-swap row-count check. Plain counts, so unlike the orphan check it
 * runs on SQLite, against a throwaway live/shadow table pair.
 *
 * The Local PHPUnit suite uses SQLite. The defensive `mysql` skip
 * keeps the test out of a misconfigured `composer test:mysql`
 * invocation (which would wipe live data via `RefreshDatabase`).
 */
class ShadowValidationShrinkTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hard-skip on real MariaDB connections. See
     * {@see DeckBulkClaimControllerTest::setUp} for
     * the rationale.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }

        foreach (['_shrink_test', '_shrink_test__shadow'] as $table) {
            Schema::create($table, fn ($t) => $t->id());
        }
    }

    #[Test]
    #[DataProvider('rowCounts')]
    public function it_flags_only_an_emptied_or_implausibly_shrunk_table(int $live, int $shadow, bool $flagged): void
    {
        $this->fill('_shrink_test', $live);
        $this->fill('_shrink_test__shadow', $shadow);

        $this->assertSame(
            $flagged ? [['table' => '_shrink_test', 'live' => $live, 'shadow' => $shadow]] : [],
            (new ShadowValidationService)->findShrunkTables(['_shrink_test']),
        );
    }

    /**
     * @return array<string, array{int, int, bool}>
     */
    public static function rowCounts(): array
    {
        return [
            'a small table emptied (rulings, symbols, bulk_data)' => [7, 0, true],
            'a small table losing a row is normal' => [7, 5, false],
            'a large table losing more than 10%' => [1000, 899, true],
            'a large table losing exactly 10%' => [1000, 900, false],
            'a large table growing' => [1000, 1200, false],
            'a fresh install with nothing live yet' => [0, 0, false],
        ];
    }

    private function fill(string $table, int $rows): void
    {
        if ($rows === 0) {
            return;
        }
        foreach (array_chunk(range(1, $rows), 500) as $ids) {
            DB::table($table)->insert(array_map(fn (int $id): array => ['id' => $id], $ids));
        }
    }
}
