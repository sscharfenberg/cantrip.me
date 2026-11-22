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
 * `artists.name` is unique, and the import's cache only knows the names it
 * inserted itself. A name the table already holds must join that artist
 * rather than fail the insert, and with it `scryfall:default_cards`; the
 * test puts the row there behind the cache's back.
 *
 * Skipped on the staging suite — uses RefreshDatabase.
 */
class ArtistsServiceTest extends TestCase
{
    use RefreshDatabase;

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
