<?php

namespace Tests\Feature;

use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Services\Scryfall\ImageOrphanScanner;
use App\Services\Scryfall\ResolveImagePathsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two readers of the image cache, against a cache that holds JPEGs
 * from before Scryfall's WebP switch next to WebPs downloaded since.
 *
 * Both used to recognize `.jpg` only. A resolver that misses a WebP leaves
 * the card on its Scryfall URL, so the site hotlinks Scryfall for every
 * new image; an orphan scanner that misreads one reports a valid file as
 * an orphan, and `scryfall:gc-images --prune` deletes it.
 *
 * Skipped on the staging suite — uses RefreshDatabase.
 */
class CachedImageFormatsTest extends TestCase
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
    public function the_resolver_points_a_card_at_its_cached_webp(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard('WebP Card', $set, [
            'art_crop' => 'https://img.test/art/x.webp?222',
            'card_image_0' => 'https://img.test/display/x-0.webp?222',
        ]);
        Storage::disk('art-crops')->put("{$set->code}/{$card->id}--222.webp", 'webp');
        Storage::disk('card-images')->put("{$set->code}/{$card->id}--222--0.webp", 'webp');

        $resolver = new ResolveImagePathsService;
        $resolver->resolveArtCropPaths();
        $resolver->resolveCardImagePaths();

        $card->refresh();
        $this->assertSame("/art-crops/{$set->code}/{$card->id}--222.webp", $card->art_crop);
        $this->assertSame("/card-images/{$set->code}/{$card->id}--222--0.webp", $card->card_image_0);
    }

    #[Test]
    public function the_resolver_keeps_each_face_on_its_own_file_whatever_the_format(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard('Half Upgraded', $set, [
            'card_image_0' => 'https://img.test/display/x-0.webp?222',
            'card_image_1' => 'https://img.test/display/x-1.webp?222',
        ]);
        Storage::disk('card-images')->put("{$set->code}/{$card->id}--222--0.jpg", 'jpeg');
        Storage::disk('card-images')->put("{$set->code}/{$card->id}--222--1.webp", 'webp');

        (new ResolveImagePathsService)->resolveCardImagePaths();

        $card->refresh();
        $this->assertSame("/card-images/{$set->code}/{$card->id}--222--0.jpg", $card->card_image_0);
        $this->assertSame("/card-images/{$set->code}/{$card->id}--222--1.webp", $card->card_image_1);
    }

    #[Test]
    public function the_orphan_scanner_counts_only_files_of_unknown_cards_in_any_format(): void
    {
        $set = $this->makeSet();
        $known = $this->makeDefaultCard('Known', $set);
        $gone = (string) Str::uuid();
        Storage::disk('art-crops')->put("{$set->code}/{$known->id}--222.webp", 'webp');
        Storage::disk('art-crops')->put("{$set->code}/{$known->id}.webp", 'untimestamped');
        Storage::disk('card-images')->put("{$set->code}/{$known->id}--222--0.jpg", 'jpeg');
        Storage::disk('art-crops')->put("{$set->code}/{$gone}--222.webp", 'orphan');
        Storage::disk('card-images')->put("{$set->code}/{$gone}--222--0.jpg", 'orphan');

        $result = (new ImageOrphanScanner)->scan(collectFiles: true);

        $this->assertSame(["{$set->code}/{$gone}--222.webp"], $result['art-crops']['files']);
        $this->assertSame(["{$set->code}/{$gone}--222--0.jpg"], $result['card-images']['files']);
    }

    private function makeSet(): Set
    {
        return Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'scryfall_uri' => 'https://example.com/set',
            'path' => '/sets/test',
        ]);
    }

    /**
     * @param  array<string, string|null>  $imageAttributes  card_image_0 / card_image_1 / art_crop
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
            ...$imageAttributes,
        ]);
    }
}
