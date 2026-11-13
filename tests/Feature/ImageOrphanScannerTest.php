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
 * and the old copy stays behind. The card still exists, so a scanner that
 * only asks "is this UUID still a card?" keeps that copy forever.
 *
 * Also covers superseded versions: a new version of an image is downloaded
 * next to the old one, and the old one is only superseded once the card's
 * live row names the new file.
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
        $card = $this->makeDefaultCard('Moved Promo', $currentSet, [
            'art_crop' => '/art-crops/pmei/CARD--222.webp',
            'card_image_0' => '/card-images/pmei/CARD--222--0.webp',
        ]);
        Storage::disk('art-crops')->put("pw25/{$card->id}--111.jpg", 'left behind');
        Storage::disk('art-crops')->put("pmei/{$card->id}--222.webp", 'current');
        Storage::disk('card-images')->put("pw25/{$card->id}--111--0.jpg", 'left behind');
        Storage::disk('card-images')->put("pmei/{$card->id}--222--0.webp", 'current');
        // A card that never moved keeps its file in the folder of its set.
        $stayed = $this->makeDefaultCard('Stayed Put', $formerSet, ['art_crop' => '/art-crops/pw25/CARD--111.jpg']);
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
        $card = $this->makeDefaultCard('Moved Card', $currentSet, ['art_crop' => '/art-crops/ana/CARD--222.webp']);
        Storage::disk('art-crops')->put("anb/{$card->id}--111.jpg", 'left behind');
        Storage::disk('art-crops')->put("ana/{$card->id}--222.webp", 'current');

        $this->artisan('scryfall:gc-images', ['--prune' => true])->assertSuccessful();

        Storage::disk('art-crops')->assertMissing("anb/{$card->id}--111.jpg");
        Storage::disk('art-crops')->assertExists("ana/{$card->id}--222.webp");
    }

    #[Test]
    public function an_older_version_of_an_image_the_card_has_a_newer_one_of_is_superseded(): void
    {
        $set = $this->makeSet('mh3');
        $card = $this->makeDefaultCard('Rescanned', $set, [
            'art_crop' => '/art-crops/mh3/CARD--222.webp',
            'card_image_0' => '/card-images/mh3/CARD--222--0.webp',
        ]);
        Storage::disk('art-crops')->put("mh3/{$card->id}--111.jpg", 'old');
        Storage::disk('art-crops')->put("mh3/{$card->id}--222.webp", 'current');
        Storage::disk('card-images')->put("mh3/{$card->id}--111--0.jpg", 'old front');
        Storage::disk('card-images')->put("mh3/{$card->id}--222--0.webp", 'current front');

        $result = (new ImageOrphanScanner)->scan(collectFiles: true);

        $this->assertSame(["mh3/{$card->id}--111.jpg"], $result['art-crops']['superseded']);
        $this->assertSame(["mh3/{$card->id}--111--0.jpg"], $result['card-images']['superseded']);
        $this->assertSame(0, $result['total']['orphans'], 'superseded versions are not orphans');
        $this->assertSame(2, $result['total']['superseded']);
    }

    #[Test]
    public function a_newer_version_that_is_not_live_yet_is_kept(): void
    {
        // A sync downloaded 333 and is still building, or aborted before its swap.
        $set = $this->makeSet('mh3');
        $card = $this->makeDefaultCard('Mid-sync', $set, ['art_crop' => '/art-crops/mh3/CARD--222.webp']);
        Storage::disk('art-crops')->put("mh3/{$card->id}--222.webp", 'live');
        Storage::disk('art-crops')->put("mh3/{$card->id}--333.webp", 'not live yet');

        $result = (new ImageOrphanScanner)->scan(collectFiles: true);

        $this->assertSame([], $result['art-crops']['superseded']);
        $this->assertSame([], $result['art-crops']['files']);
    }

    #[Test]
    public function the_last_image_of_a_card_is_kept_when_the_named_file_is_gone(): void
    {
        $set = $this->makeSet('mh3');
        $card = $this->makeDefaultCard('Named File Missing', $set, ['art_crop' => '/art-crops/mh3/CARD--222.webp']);
        Storage::disk('art-crops')->put("mh3/{$card->id}--111.jpg", 'the only image there is');

        $result = (new ImageOrphanScanner)->scan(collectFiles: true);

        $this->assertSame([], $result['art-crops']['superseded']);
    }

    #[Test]
    public function an_image_the_card_no_longer_has_is_an_orphan(): void
    {
        // Two faces once; the live row names only the front now.
        $set = $this->makeSet('mh3');
        $card = $this->makeDefaultCard('Lost Its Back', $set, ['card_image_0' => '/card-images/mh3/CARD--222--0.webp']);
        Storage::disk('card-images')->put("mh3/{$card->id}--222--0.webp", 'front');
        Storage::disk('card-images')->put("mh3/{$card->id}--111--1.jpg", 'back');

        $result = (new ImageOrphanScanner)->scan(collectFiles: true);

        $this->assertSame(["mh3/{$card->id}--111--1.jpg"], $result['card-images']['files']);
        $this->assertSame([], $result['card-images']['superseded']);
    }

    #[Test]
    public function every_version_is_kept_while_the_card_still_points_at_scryfall(): void
    {
        $set = $this->makeSet('mh3');
        $card = $this->makeDefaultCard('Download Failed', $set, [
            'art_crop' => 'https://img.test/art/failed.webp?222',
        ]);
        Storage::disk('art-crops')->put("mh3/{$card->id}--111.jpg", 'the only image there is');

        $result = (new ImageOrphanScanner)->scan(collectFiles: true);

        $this->assertSame([], $result['art-crops']['superseded']);
        $this->assertSame([], $result['art-crops']['files']);
    }

    #[Test]
    public function a_card_image_without_a_face_index_is_an_orphan(): void
    {
        $set = $this->makeSet('mh3');
        $card = $this->makeDefaultCard('Stray', $set, ['card_image_0' => '/card-images/mh3/CARD--222--0.webp']);
        Storage::disk('card-images')->put("mh3/{$card->id}--222--0.webp", 'front');
        Storage::disk('card-images')->put("mh3/{$card->id}--222.webp", 'an art-crop-style name, no face');

        $result = (new ImageOrphanScanner)->scan(collectFiles: true);

        $this->assertSame(["mh3/{$card->id}--222.webp"], $result['card-images']['files']);
    }

    #[Test]
    public function a_part_file_left_by_a_killed_download_is_an_orphan(): void
    {
        $set = $this->makeSet('mh3');
        $card = $this->makeDefaultCard('Interrupted', $set);
        Storage::disk('art-crops')->put("mh3/.{$card->id}--222.webp.part", 'half');

        $result = (new ImageOrphanScanner)->scan(collectFiles: true);

        $this->assertSame(["mh3/.{$card->id}--222.webp.part"], $result['art-crops']['files']);
    }

    #[Test]
    public function prune_also_deletes_superseded_versions(): void
    {
        $set = $this->makeSet('mh3');
        $card = $this->makeDefaultCard('Rescanned', $set, ['art_crop' => '/art-crops/mh3/CARD--222.webp']);
        Storage::disk('art-crops')->put("mh3/{$card->id}--111.jpg", 'old');
        Storage::disk('art-crops')->put("mh3/{$card->id}--222.webp", 'current');

        $this->artisan('scryfall:gc-images', ['--prune' => true])->assertSuccessful();

        Storage::disk('art-crops')->assertMissing("mh3/{$card->id}--111.jpg");
        Storage::disk('art-crops')->assertExists("mh3/{$card->id}--222.webp");
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

    /**
     * @param  array<string, string>  $imageAttributes  Image columns; `CARD` in a value is replaced by the card's id.
     */
    private function makeDefaultCard(string $name, Set $set, array $imageAttributes = []): DefaultCard
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

        $id = (string) Str::uuid();

        return DefaultCard::create([
            'id' => $id,
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
            ...array_map(fn (string $value): string => str_replace('CARD', $id, $value), $imageAttributes),
        ]);
    }
}
