<?php

namespace Tests\Feature;

use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Services\Scryfall\ImageDownloadService;
use App\Services\Scryfall\ImageOrphanScanner;
use App\Services\Scryfall\ScryfallRunStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for the per-image-type counters ImageDownloadService publishes
 * to ScryfallRunStats. UpdateEverything reads these back into its
 * end-of-run summary, so the contract under test is: after a download
 * pass, the static counters reflect what happened on disk (downloaded /
 * skipped / failed), separately for card faces and art crops.
 *
 * Also covers how a new version of an image arrives: next to the old one,
 * never in its place — during a shadow build the live rows still point at
 * the old file, and a failed download must not cost it — and that the old
 * one goes once the swap has made it superseded, where a file the running
 * user cannot delete must not abort the pass. It used to: Flysystem's
 * UnableToDeleteFile killed the whole nightly scryfall:update on the first
 * undeletable file it met.
 *
 * Skipped on the staging suite — uses RefreshDatabase.
 */
class ImageDownloadServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> Directories chmod'ed read-only by a test. */
    private array $lockedDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Uses RefreshDatabase; never run against MariaDB.');
        }
        ScryfallRunStats::reset();
        Storage::fake('card-images');
        Storage::fake('art-crops');
    }

    protected function tearDown(): void
    {
        // A directory left read-only would break the next Storage::fake()
        // cleanup, so unlock it whatever the test did.
        foreach ($this->lockedDirectories as $path) {
            @chmod($path, 0755);
        }
        $this->lockedDirectories = [];

        parent::tearDown();
    }

    #[Test]
    public function card_image_download_records_per_face_stats(): void
    {
        $set = $this->makeSet();

        // One face to fetch.
        $this->makeDefaultCard('Front Only', $set, [
            'card_image_0' => 'https://img.test/front-only-0.jpg?111',
        ]);

        // Two faces: face 0 already cached (skipped), face 1 fetched.
        $twoFace = $this->makeDefaultCard('Two Face', $set, [
            'card_image_0' => 'https://img.test/two-face-0.jpg?222',
            'card_image_1' => 'https://img.test/two-face-1.jpg?333',
        ]);
        Storage::disk('card-images')->put("{$set->code}/{$twoFace->id}--222--0.jpg", 'cached');

        // One face that fails to download (HTTP 404).
        $this->makeDefaultCard('Broken', $set, [
            'card_image_0' => 'https://img.test/broken-0.jpg?444',
        ]);

        Http::fake([
            'img.test/broken-0.jpg*' => Http::response('', 404),
            '*' => Http::response('fake-image-bytes', 200),
        ]);

        (new ImageDownloadService)->downloadCardImages();

        $this->assertSame(2, ScryfallRunStats::$cardImagesDownloaded, 'front-only face + two-face back');
        $this->assertSame(1, ScryfallRunStats::$cardImagesSkipped, 'two-face front already cached');
        $this->assertSame(1, ScryfallRunStats::$cardImagesFailed, 'broken face 404');

        // Art-crop counters must be untouched by a card-image pass.
        $this->assertSame(0, ScryfallRunStats::$artCropsDownloaded);
        $this->assertSame(0, ScryfallRunStats::$artCropsSkipped);
        $this->assertSame(0, ScryfallRunStats::$artCropsFailed);
    }

    #[Test]
    public function art_crop_download_records_per_card_stats(): void
    {
        $set = $this->makeSet();

        $this->makeDefaultCard('Fresh Crop', $set, [
            'art_crop' => 'https://img.test/fresh.jpg?111',
        ]);

        $cached = $this->makeDefaultCard('Cached Crop', $set, [
            'art_crop' => 'https://img.test/cached.jpg?222',
        ]);
        Storage::disk('art-crops')->put("{$set->code}/{$cached->id}--222.jpg", 'cached');

        $this->makeDefaultCard('Broken Crop', $set, [
            'art_crop' => 'https://img.test/broken.jpg?444',
        ]);

        Http::fake([
            'img.test/broken.jpg*' => Http::response('', 404),
            '*' => Http::response('fake-image-bytes', 200),
        ]);

        (new ImageDownloadService)->downloadArtCrops();

        $this->assertSame(1, ScryfallRunStats::$artCropsDownloaded);
        $this->assertSame(1, ScryfallRunStats::$artCropsSkipped);
        $this->assertSame(1, ScryfallRunStats::$artCropsFailed);

        // Card-image counters must be untouched by an art-crop pass.
        $this->assertSame(0, ScryfallRunStats::$cardImagesDownloaded);
        $this->assertSame(0, ScryfallRunStats::$cardImagesSkipped);
        $this->assertSame(0, ScryfallRunStats::$cardImagesFailed);
    }

    #[Test]
    public function an_unwritable_set_folder_fails_that_card_not_the_pass(): void
    {
        $lockedSet = $this->makeSet();
        $writableSet = $this->makeSet();
        $stranded = $this->makeDefaultCard('Stranded Crop', $lockedSet, [
            'art_crop' => 'https://img.test/stranded.jpg?999',
        ]);
        Storage::disk('art-crops')->put("{$lockedSet->code}/{$stranded->id}--111.jpg", 'old');
        $healthy = $this->makeDefaultCard('Healthy Crop', $writableSet, [
            'art_crop' => 'https://img.test/healthy.jpg?222',
        ]);
        $this->lockDirectory(Storage::disk('art-crops')->path($lockedSet->code));
        Http::fake(['*' => Http::response('fake-image-bytes', 200)]);

        (new ImageDownloadService)->downloadArtCrops();

        $this->assertSame(1, ScryfallRunStats::$artCropsFailed);
        $this->assertSame(1, ScryfallRunStats::$artCropsDownloaded);
        Storage::disk('art-crops')->assertExists("{$writableSet->code}/{$healthy->id}--222.jpg");
    }

    #[Test]
    public function a_failed_download_keeps_the_old_version(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard('Rescanned', $set, [
            'card_image_0' => 'https://img.test/display/rescanned-0.webp?999',
            'art_crop' => 'https://img.test/art/rescanned.webp?999',
        ]);
        Storage::disk('card-images')->put("{$set->code}/{$card->id}--111--0.jpg", 'old');
        Storage::disk('art-crops')->put("{$set->code}/{$card->id}--111.jpg", 'old');
        Http::fake(['*' => Http::response('', 503)]);

        $service = new ImageDownloadService;
        $service->downloadCardImages();
        $service->downloadArtCrops();

        $this->assertSame(1, ScryfallRunStats::$cardImagesFailed);
        $this->assertSame(1, ScryfallRunStats::$artCropsFailed);
        Storage::disk('card-images')->assertExists("{$set->code}/{$card->id}--111--0.jpg");
        Storage::disk('art-crops')->assertExists("{$set->code}/{$card->id}--111.jpg");
    }

    #[Test]
    public function an_empty_response_is_a_failure_and_leaves_no_file(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard('Empty', $set, ['art_crop' => 'https://img.test/art/empty.webp?999']);
        Http::fake(['*' => Http::response('', 200)]);

        (new ImageDownloadService)->downloadArtCrops();

        $this->assertSame(1, ScryfallRunStats::$artCropsFailed);
        $this->assertSame([], Storage::disk('art-crops')->allFiles(), 'neither the image nor a .part file');
    }

    #[Test]
    public function a_download_lands_complete_under_its_final_name_only(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard('Fresh', $set, ['card_image_0' => 'https://img.test/display/fresh-0.webp?999']);
        Http::fake(['*' => Http::response('webp-bytes', 200)]);

        (new ImageDownloadService)->downloadCardImages();

        $this->assertSame(["{$set->code}/{$card->id}--999--0.webp"], Storage::disk('card-images')->allFiles());
        $this->assertSame('webp-bytes', Storage::disk('card-images')->get("{$set->code}/{$card->id}--999--0.webp"));
    }

    /**
     * The WebP switch must not re-download the library: Scryfall's WebP URL
     * carries the same timestamp as the JPEG we cached, so the JPEG is the
     * same image and stays. A genuinely new image arrives as WebP.
     */
    #[Test]
    public function a_cached_jpeg_of_the_same_image_counts_as_current_when_scryfall_serves_webp(): void
    {
        $set = $this->makeSet();
        $cached = $this->makeDefaultCard('Cached Before The Switch', $set, [
            'card_image_0' => 'https://img.test/display/cached-0.webp?222',
            'art_crop' => 'https://img.test/art/cached.webp?222',
        ]);
        Storage::disk('card-images')->put("{$set->code}/{$cached->id}--222--0.jpg", 'jpeg');
        Storage::disk('art-crops')->put("{$set->code}/{$cached->id}--222.jpg", 'jpeg');
        $new = $this->makeDefaultCard('New Card', $set, [
            'card_image_0' => 'https://img.test/display/new-0.webp?333',
            'art_crop' => 'https://img.test/art/new.webp?333',
        ]);
        Http::fake(['*' => Http::response('webp-bytes', 200)]);

        $service = new ImageDownloadService;
        $service->downloadCardImages();
        $service->downloadArtCrops();

        $this->assertSame(1, ScryfallRunStats::$cardImagesSkipped);
        $this->assertSame(1, ScryfallRunStats::$artCropsSkipped);
        Http::assertSentCount(2);
        Storage::disk('card-images')->assertExists("{$set->code}/{$cached->id}--222--0.jpg");
        Storage::disk('card-images')->assertExists("{$set->code}/{$new->id}--333--0.webp");
        Storage::disk('art-crops')->assertExists("{$set->code}/{$new->id}--333.webp");
    }

    #[Test]
    public function a_changed_image_is_downloaded_next_to_the_old_version(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard('Rescanned', $set, [
            'card_image_0' => 'https://img.test/display/rescanned-0.webp?999',
            'art_crop' => 'https://img.test/art/rescanned.webp?999',
        ]);
        Storage::disk('card-images')->put("{$set->code}/{$card->id}--111--0.jpg", 'old');
        Storage::disk('card-images')->put("{$set->code}/{$card->id}--111--1.jpg", 'other face');
        Storage::disk('art-crops')->put("{$set->code}/{$card->id}--111.jpg", 'old');
        Http::fake(['*' => Http::response('webp-bytes', 200)]);

        $service = new ImageDownloadService;
        $service->downloadCardImages();
        $service->downloadArtCrops();

        Storage::disk('card-images')->assertExists("{$set->code}/{$card->id}--999--0.webp");
        // The old versions stay: live still points at them until the swap.
        Storage::disk('card-images')->assertExists("{$set->code}/{$card->id}--111--0.jpg");
        Storage::disk('card-images')->assertExists("{$set->code}/{$card->id}--111--1.jpg");
        Storage::disk('art-crops')->assertExists("{$set->code}/{$card->id}--999.webp");
        Storage::disk('art-crops')->assertExists("{$set->code}/{$card->id}--111.jpg");
    }

    #[Test]
    public function after_the_swap_the_superseded_version_goes_and_an_undeletable_one_is_counted(): void
    {
        $lockedSet = $this->makeSet();
        $writableSet = $this->makeSet();
        $stranded = $this->makeDefaultCard('Stranded', $lockedSet);
        $this->resolveArtCrop($stranded, $lockedSet, '222.webp');
        Storage::disk('art-crops')->put("{$lockedSet->code}/{$stranded->id}--111.jpg", 'superseded');
        $healthy = $this->makeDefaultCard('Healthy', $writableSet);
        $this->resolveArtCrop($healthy, $writableSet, '222.webp');
        Storage::disk('art-crops')->put("{$writableSet->code}/{$healthy->id}--111.jpg", 'superseded');
        $this->lockDirectory(Storage::disk('art-crops')->path($lockedSet->code));

        $deleted = (new ImageDownloadService)->deleteSuperseded((new ImageOrphanScanner)->scan());

        $this->assertSame(1, $deleted);
        $this->assertSame(1, ScryfallRunStats::$staleFilesUndeletable, 'counted, not thrown');
        Storage::disk('art-crops')->assertMissing("{$writableSet->code}/{$healthy->id}--111.jpg");
        Storage::disk('art-crops')->assertExists("{$writableSet->code}/{$healthy->id}--222.webp");
        Storage::disk('art-crops')->assertExists("{$lockedSet->code}/{$stranded->id}--111.jpg");
    }

    /**
     * Put the card's art crop on disk under $version and point its row at
     * it, the way resolve-paths leaves a card after the swap.
     */
    private function resolveArtCrop(DefaultCard $card, Set $set, string $version): void
    {
        $path = "{$set->code}/{$card->id}--$version";
        Storage::disk('art-crops')->put($path, 'current');
        $card->update(['art_crop' => "/art-crops/$path"]);
    }

    /**
     * Strip write permission from a directory so unlink() inside it fails.
     *
     * Skips the test when the permission bits have no effect — running as
     * root, or on a filesystem that ignores them — rather than asserting on
     * a condition that was never actually created.
     */
    private function lockDirectory(string $path): void
    {
        chmod($path, 0555);
        $this->lockedDirectories[] = $path;

        $probe = $path.'/.write-probe';
        if (@file_put_contents($probe, 'x') !== false) {
            @unlink($probe);
            $this->markTestSkipped('Directory permissions are not enforced for this user.');
        }
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
