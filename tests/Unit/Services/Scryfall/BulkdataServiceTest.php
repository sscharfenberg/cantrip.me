<?php

namespace Tests\Unit\Services\Scryfall;

use App\Services\Scryfall\BulkdataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\DeckBulkClaimControllerTest;
use Tests\TestCase;

/**
 * Coverage for {@see BulkdataService}, the entry point of every Scryfall
 * import — it resolves the download URIs the four bulk importers read.
 *
 * Scryfall's /bulk-data response is faked, so these tests pin the
 * field-name contract without network I/O. That contract is the whole
 * point: an unannounced `size` → `compressed_size` rename broke the
 * nightly `scryfall:update` with an `Undefined array key` fatal.
 *
 * Runs against shadow tables where a write is needed, because the live
 * path's `preRunCleanup()` issues MariaDB-only
 * `SET FOREIGN_KEY_CHECKS` statements that SQLite cannot parse.
 *
 * The Local PHPUnit suite uses SQLite. The defensive `mysql` skip
 * keeps the test out of a misconfigured `composer test:mysql`
 * invocation (which would wipe live data via `RefreshDatabase`).
 */
class BulkdataServiceTest extends TestCase
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
    }

    /**
     * Stand in for the shadow table the orchestrator would have created
     * via `CREATE TABLE LIKE` before invoking the service.
     */
    private function createShadowTable(): void
    {
        DB::statement('CREATE TABLE bulk_data__shadow AS SELECT * FROM bulk_data WHERE 0');
    }

    /**
     * Fake the Scryfall /bulk-data catalog with a single entry, shaped
     * exactly as the live API returns it today.
     *
     * @param  array<string, mixed>  $entry
     */
    private function fakeCatalog(array $entry): void
    {
        Http::fake([
            'api.scryfall.com/bulk-data' => Http::response([
                'object' => 'list',
                'has_more' => false,
                'data' => [$entry],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentApiEntry(): array
    {
        return [
            'object' => 'bulk_data',
            'id' => '06f54c0b-ab9c-452d-b35a-8297db5eb940',
            'type' => 'rulings',
            'updated_at' => '2026-07-28T21:00:37.120+00:00',
            'uri' => 'https://api.scryfall.com/bulk-data/06f54c0b-ab9c-452d-b35a-8297db5eb940',
            'name' => 'Rulings',
            'description' => 'A JSON file containing all Rulings on Scryfall.',
            'jsonl_download_uri' => 'https://data.scryfall.io/rulings/rulings-20260728210037.jsonl.gz',
            'compressed_size' => 5300458,
        ];
    }

    #[Test]
    public function it_maps_compressed_size_and_jsonl_download_uri_onto_the_bulk_data_row(): void
    {
        $this->createShadowTable();
        $this->fakeCatalog($this->currentApiEntry());

        (new BulkdataService)->getBulkMetadata(shadow: true);

        $row = DB::table('bulk_data__shadow')->where('type', 'rulings')->first();

        $this->assertNotNull($row);
        $this->assertSame(5300458, (int) $row->size);
        $this->assertSame(
            'https://data.scryfall.io/rulings/rulings-20260728210037.jsonl.gz',
            $row->download_uri
        );
        $this->assertSame('2026-07-28 21:00:37', (string) $row->updated_at);
    }

    #[Test]
    public function it_fails_with_a_named_error_when_scryfall_renames_a_bulk_field(): void
    {
        $this->createShadowTable();
        $legacy = $this->currentApiEntry();
        unset($legacy['compressed_size'], $legacy['jsonl_download_uri']);
        $legacy['size'] = 25_000_000;
        $legacy['download_uri'] = 'https://data.scryfall.io/rulings/rulings-20260728210037.json';
        $this->fakeCatalog($legacy);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing expected field\(s\): compressed_size, jsonl_download_uri/');

        (new BulkdataService)->getBulkMetadata(shadow: true);
    }

    #[Test]
    public function it_resolves_a_stored_download_uri_by_type(): void
    {
        DB::table('bulk_data')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'default_cards',
            'updated_at' => now()->toDateTimeString(),
            'uri' => 'https://api.scryfall.com/bulk-data/x',
            'name' => 'Default Cards',
            'description' => 'fixture',
            'size' => 77013126,
            'download_uri' => 'https://data.scryfall.io/default-cards/default-cards-20260728235710.jsonl.gz',
        ]);

        $service = new BulkdataService;

        $this->assertSame(
            'https://data.scryfall.io/default-cards/default-cards-20260728235710.jsonl.gz',
            $service->resolveDownloadUri('default_cards')
        );
        $this->assertNull($service->resolveDownloadUri('all_cards'));
    }
}
