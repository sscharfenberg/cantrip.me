<?php

namespace Tests\Feature;

use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Services\Scryfall\ImageOrphanScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Images live under `{set code}/`. When Scryfall moves a printing to
 * another set, the next run downloads its image into the new set's folder
 * and `cleanupOldVersions()` — which only looks there — never sees the old
 * copy. The card still exists, so a scanner that only asks "is this UUID
 * still a card?" keeps that copy forever.
 *
 * Skipped on the staging suite — uses RefreshDatabase.
 */
class ImageOrphanScannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Uses RefreshDatabase; never run against MariaDB.');
        }
        Storage::fake('card-images');
        Storage::fake('art-crops');
    }

    #[Test]
    public function a_copy_left_in_the_folder_of_the_cards_former_set_is_an_orphan(): void
    {
        $formerSet = $this->makeSet('pw25');
        $currentSet = $this->makeSet('pmei');
        $card = $this->makeDefaultCard('Moved Promo', $currentSet);
        Storage::disk('art-crops')->put("pw25/{$card->id}--111.jpg", 'left behind');
        Storage::disk('art-crops')->put("pmei/{$card->id}--222.webp", 'current');
        Storage::disk('card-images')->put("pw25/{$card->id}--111--0.jpg", 'left behind');
        Storage::disk('card-images')->put("pmei/{$card->id}--222--0.webp", 'current');
        // A card that never moved keeps its file in the folder of its set.
        $stayed = $this->makeDefaultCard('Stayed Put', $formerSet);
        Storage::disk('art-crops')->put("pw25/{$stayed->id}--111.jpg", 'current');

        $result = (new ImageOrphanScanner)->scan(collectFiles: true);

        $this->assertSame(["pw25/{$card->id}--111.jpg"], $result['art-crops']['files']);
        $this->assertSame(["pw25/{$card->id}--111--0.jpg"], $result['card-images']['files']);
        $this->assertSame(2, $result['total']['orphans']);
        $this->assertSame(5, $result['total']['scanned']);
    }

    #[Test]
    public function prune_deletes_the_left_behind_copy_and_keeps_the_current_one(): void
    {
        $this->makeSet('anb');
        $currentSet = $this->makeSet('ana');
        $card = $this->makeDefaultCard('Moved Card', $currentSet);
        Storage::disk('art-crops')->put("anb/{$card->id}--111.jpg", 'left behind');
        Storage::disk('art-crops')->put("ana/{$card->id}--222.webp", 'current');

        $this->artisan('scryfall:gc-images', ['--prune' => true])->assertSuccessful();

        Storage::disk('art-crops')->assertMissing("anb/{$card->id}--111.jpg");
        Storage::disk('art-crops')->assertExists("ana/{$card->id}--222.webp");
    }

    private function makeSet(string $code): Set
    {
        return Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Set '.$code,
            'code' => $code,
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'scryfall_uri' => 'https://example.com/set',
            'path' => '/sets/test',
        ]);
    }

    private function makeDefaultCard(string $name, Set $set): DefaultCard
    {
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }
}
