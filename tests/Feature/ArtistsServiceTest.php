<?php

namespace Tests\Feature;

use App\Services\Scryfall\ArtistsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `artists.name` is unique under a collation that ignores case and
 * accents, while the import's cache compares bytes. Two spellings the
 * index calls equal used to fail the second insert, and with it
 * `scryfall:default_cards`. SQLite's index compares bytes, so the test
 * reaches the same state by putting the row there behind the cache's back.
 *
 * Skipped on the staging suite — uses RefreshDatabase.
 */
class ArtistsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Uses RefreshDatabase; never run against MariaDB.');
        }
    }

    #[Test]
    public function a_name_the_unique_index_already_holds_joins_that_artist(): void
    {
        Log::shouldReceive('channel')->with('scryfall')->andReturnSelf();
        Log::shouldReceive('info')->once();
        $existing = (string) Str::uuid();
        DB::table('artists')->insert(['id' => $existing, 'name' => 'José Pérez']);
        $service = new ArtistsService;

        $this->assertSame($existing, $service->resolveArtistId('José Pérez'));
        $this->assertSame($existing, $service->resolveArtistId('José Pérez'), 'cached from now on');
        $this->assertSame(1, DB::table('artists')->count());
    }

    #[Test]
    public function a_new_name_gets_a_new_artist(): void
    {
        $service = new ArtistsService;

        $first = $service->resolveArtistId('Rebecca Guay');
        $second = $service->resolveArtistId('Terese Nielsen');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, DB::table('artists')->count());
    }
}
