<?php

namespace Tests\Feature;

use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Services\Scryfall\ResolveImagePathsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A new version of an image is downloaded next to the old one, so after
 * the image step a card can have both on disk. Resolution must pick the
 * version the bulk's URL names — and fall back to the old one only when
 * the new download failed, rather than leaving the card on Scryfall's CDN.
 *
 * Skipped on the staging suite — uses RefreshDatabase.
 */
class ResolveImagePathsServiceTest extends TestCase
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
    public function the_version_the_url_names_wins_over_an_older_one(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard($set, [
            'art_crop' => 'https://img.test/art/card.webp?222',
            'card_image_1' => 'https://img.test/display/card-1.webp?222',
        ]);
        // Older first in listing order, so "first file of the card" would pick it.
        Storage::disk('art-crops')->put("{$set->code}/{$card->id}--111.jpg", 'old');
        Storage::disk('art-crops')->put("{$set->code}/{$card->id}--222.webp", 'new');
        Storage::disk('card-images')->put("{$set->code}/{$card->id}--111--1.jpg", 'old back');
        Storage::disk('card-images')->put("{$set->code}/{$card->id}--222--0.webp", 'front');
        Storage::disk('card-images')->put("{$set->code}/{$card->id}--222--1.webp", 'new back');

        $service = new ResolveImagePathsService;
        $service->resolveArtCropPaths();
        $service->resolveCardImagePaths();

        $card->refresh();
        $this->assertSame("/art-crops/{$set->code}/{$card->id}--222.webp", $card->art_crop);
        $this->assertSame("/card-images/{$set->code}/{$card->id}--222--1.webp", $card->card_image_1);
    }

    #[Test]
    public function the_same_version_in_another_format_counts(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard($set, ['art_crop' => 'https://img.test/art/card.webp?222']);
        Storage::disk('art-crops')->put("{$set->code}/{$card->id}--111.webp", 'old');
        Storage::disk('art-crops')->put("{$set->code}/{$card->id}--222.jpg", 'same image, cached before the WebP switch');

        (new ResolveImagePathsService)->resolveArtCropPaths();

        $this->assertSame("/art-crops/{$set->code}/{$card->id}--222.jpg", $card->refresh()->art_crop);
    }

    #[Test]
    public function an_older_version_stands_in_when_the_new_download_failed(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard($set, ['art_crop' => 'https://img.test/art/card.webp?222']);
        Storage::disk('art-crops')->put("{$set->code}/{$card->id}--111.jpg", 'old');

        (new ResolveImagePathsService)->resolveArtCropPaths();

        $this->assertSame("/art-crops/{$set->code}/{$card->id}--111.jpg", $card->refresh()->art_crop);
    }

    #[Test]
    public function a_part_file_is_never_resolved_to(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard($set, ['art_crop' => 'https://img.test/art/card.webp?222']);
        Storage::disk('art-crops')->put("{$set->code}/.{$card->id}--222.webp.part", 'half');

        (new ResolveImagePathsService)->resolveArtCropPaths();

        $this->assertSame('https://img.test/art/card.webp?222', $card->refresh()->art_crop);
    }

    private function makeSet(): Set
    {
        return Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Set',
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'scryfall_uri' => 'https://example.com/set',
            'path' => '/sets/test',
        ]);
    }

    /**
     * @param  array<string, string>  $imageAttributes  card_image_0 / card_image_1 / art_crop
     */
    private function makeDefaultCard(Set $set, array $imageAttributes): DefaultCard
    {
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => 'Card',
            'searchable_name' => 'card',
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'scryfall_uri' => 'https://example.com/card',
        ]);

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => 'Card',
            'searchable_name' => 'card',
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
