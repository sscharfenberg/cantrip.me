<?php

namespace Tests\Unit\Services\Scryfall;

use App\Services\Scryfall\DefaultCardsService;
use App\Services\Scryfall\OracleCardsService;
use App\Services\Scryfall\SetsService;
use Closure;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The imports of tables user data references — sets, oracle cards,
 * printings (and with them artists) — only ever build shadow tables.
 * Postgres will not truncate a table an FK points at, and `TRUNCATE …
 * CASCADE` would empty the user tables pointing at it, so a standalone
 * live run must stop before it asks Scryfall for anything.
 */
class LiveImportRefusalTest extends TestCase
{
    /**
     * @param  Closure(): void  $import
     */
    #[Test]
    #[DataProvider('referencedTableImports')]
    public function a_live_import_is_refused_before_any_request(string $command, Closure $import): void
    {
        Http::fake();

        try {
            $import();
            $this->fail("$command was expected to refuse a live import");
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith("$command only builds shadow tables", $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string, Closure(): void}>
     */
    public static function referencedTableImports(): array
    {
        return [
            'sets' => ['scryfall:sets', fn () => (new SetsService)->updateSets()],
            'oracle cards' => ['scryfall:oracle', fn () => (new OracleCardsService)->updateOracleCards()],
            'default cards' => ['scryfall:default_cards', fn () => (new DefaultCardsService)->updateAllCards()],
        ];
    }
}
