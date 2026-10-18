<?php

namespace Tests\Feature;

use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Services\Scryfall\ImageDownloadService;
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
 * Also covers the resilience contract around stale-file cleanup: a cached
 * file the running user cannot delete must not abort the pass. It used to —
 * Flysystem's UnableToDeleteFile escaped cleanupOldVersions() and killed the
 * whole nightly scryfall:update on the first undeletable file it met.
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
    public function an_undeletable_stale_file_does_not_abort_the_pass(): void
    {
        $lockedSet = $this->makeSet();
        $writableSet = $this->makeSet();

        // A superseded crop (timestamp 111) in a set directory the process
        // cannot write to — mirrors production, where the shared asset dirs
        // were owned by the previous cron user and not group-writable.
        $stranded = $this->makeDefaultCard('Stranded Crop', $lockedSet, [
            'art_crop' => 'https://img.test/stranded.jpg?999',
        ]);
        $staleFile = "{$lockedSet->code}/{$stranded->id}--111.jpg";
        Storage::disk('art-crops')->put($staleFile, 'superseded');

        // An unrelated card in a healthy directory: the pass must reach it.
        $healthy = $this->makeDefaultCard('Healthy Crop', $writableSet, [
            'art_crop' => 'https://img.test/healthy.jpg?222',
        ]);

        $this->lockDirectory(Storage::disk('art-crops')->path($lockedSet->code));

        Http::fake(['*' => Http::response('fake-image-bytes', 200)]);

        (new ImageDownloadService)->downloadArtCrops();

        $this->assertSame(
            1,
            ScryfallRunStats::$staleFilesUndeletable,
            'the undeletable stale crop must be counted, not thrown'
        );
        $this->assertTrue(
            Storage::disk('art-crops')->exists($staleFile),
            'the stale file stays on disk — cleanup failure is tolerated, not retried'
        );

        // The pass continued past the failure and cached the other card.
        $this->assertSame(1, ScryfallRunStats::$artCropsDownloaded);
        $this->assertTrue(
            Storage::disk('art-crops')->exists("{$writableSet->code}/{$healthy->id}--222.jpg"),
            'the healthy card must still be downloaded'
        );

        // The locked card could not be written either, so it counts as failed
        // rather than downloaded — degraded, but the run survives.
        $this->assertSame(1, ScryfallRunStats::$artCropsFailed);
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
    public function a_changed_image_replaces_the_cached_jpeg_with_the_new_webp(): void
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
        Storage::disk('card-images')->assertMissing("{$set->code}/{$card->id}--111--0.jpg");
        Storage::disk('card-images')->assertExists("{$set->code}/{$card->id}--111--1.jpg");
        Storage::disk('art-crops')->assertExists("{$set->code}/{$card->id}--999.webp");
        Storage::disk('art-crops')->assertMissing("{$set->code}/{$card->id}--111.jpg");
    }

    #[Test]
    public function the_backfill_replaces_cached_jpegs_with_their_webp(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard('Double Faced', $set, [
            'card_image_0' => 'https://img.test/display/double-0.webp?222',
            'card_image_1' => 'https://img.test/display/double-1.webp?222',
            'art_crop' => 'https://img.test/art/double.webp?222',
        ]);
        foreach (['card-images' => ['--222--0', '--222--1'], 'art-crops' => ['--222']] as $disk => $suffixes) {
            foreach ($suffixes as $suffix) {
                Storage::disk($disk)->put("{$set->code}/{$card->id}{$suffix}.jpg", 'jpeg');
            }
        }
        Http::fake(['*' => Http::response('webp-bytes', 200)]);

        (new ImageDownloadService)->upgradeToWebp('live', 100);

        $this->assertSame(3, ScryfallRunStats::$webpUpgraded);
        $this->assertTrue(ScryfallRunStats::$webpBackfillComplete);
        foreach (['card-images' => ['--222--0', '--222--1'], 'art-crops' => ['--222']] as $disk => $suffixes) {
            foreach ($suffixes as $suffix) {
                Storage::disk($disk)->assertMissing("{$set->code}/{$card->id}{$suffix}.jpg");
                $this->assertSame('webp-bytes', Storage::disk($disk)->get("{$set->code}/{$card->id}{$suffix}.webp"));
            }
        }
    }

    #[Test]
    public function the_backfill_stops_at_its_limit_and_says_it_is_not_done(): void
    {
        $set = $this->makeSet();
        foreach (range(1, 5) as $n) {
            $card = $this->makeDefaultCard("Crop $n", $set, ['art_crop' => "https://img.test/art/crop-$n.webp?222"]);
            Storage::disk('art-crops')->put("{$set->code}/{$card->id}--222.jpg", 'jpeg');
        }
        Http::fake(['*' => Http::response('webp-bytes', 200)]);

        (new ImageDownloadService)->upgradeToWebp('live', 3);

        Http::assertSentCount(3);
        $this->assertSame(3, ScryfallRunStats::$webpUpgraded);
        $this->assertFalse(ScryfallRunStats::$webpBackfillComplete);
        $this->assertCount(2, array_filter(Storage::disk('art-crops')->files($set->code), fn ($f) => str_ends_with($f, '.jpg')));
    }

    #[Test]
    public function the_backfill_counts_a_failed_download_against_its_limit_and_keeps_the_jpeg(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard('Unreachable', $set, ['art_crop' => 'https://img.test/art/unreachable.webp?222']);
        Storage::disk('art-crops')->put("{$set->code}/{$card->id}--222.jpg", 'jpeg');
        $other = $this->makeDefaultCard('Also Unreachable', $set, ['art_crop' => 'https://img.test/art/other.webp?222']);
        Storage::disk('art-crops')->put("{$set->code}/{$other->id}--222.jpg", 'jpeg');
        Http::fake(['*' => Http::response('', 503)]);

        (new ImageDownloadService)->upgradeToWebp('live', 1);

        // A failing Scryfall must not turn into one request per cached JPEG.
        Http::assertSentCount(1);
        $this->assertSame(1, ScryfallRunStats::$webpFailed);
        $this->assertFalse(ScryfallRunStats::$webpBackfillComplete);
        Storage::disk('art-crops')->assertExists("{$set->code}/{$card->id}--222.jpg");
        Storage::disk('art-crops')->assertMissing("{$set->code}/{$card->id}--222.webp");
    }

    #[Test]
    public function the_backfill_leaves_alone_what_it_cannot_or_need_not_upgrade(): void
    {
        $set = $this->makeSet();
        // Already WebP.
        $webp = $this->makeDefaultCard('Already WebP', $set, ['art_crop' => 'https://img.test/art/webp.webp?222']);
        Storage::disk('art-crops')->put("{$set->code}/{$webp->id}--222.webp", 'webp');
        // Not cached at all — the download pass's job, not the backfill's.
        $this->makeDefaultCard('Not Cached', $set, ['art_crop' => 'https://img.test/art/missing.webp?222']);
        // Scryfall still serves this one as JPEG only.
        $jpegOnly = $this->makeDefaultCard('JPEG Only', $set, ['art_crop' => 'https://img.test/art_crop/old.jpg?222']);
        Storage::disk('art-crops')->put("{$set->code}/{$jpegOnly->id}--222.jpg", 'jpeg');
        Http::fake(['*' => Http::response('webp-bytes', 200)]);

        (new ImageDownloadService)->upgradeToWebp('live', 100);

        Http::assertNothingSent();
        $this->assertSame(0, ScryfallRunStats::$webpUpgraded);
        $this->assertTrue(ScryfallRunStats::$webpBackfillComplete);
        Storage::disk('art-crops')->assertExists("{$set->code}/{$jpegOnly->id}--222.jpg");
    }

    #[Test]
    public function a_backfill_limit_of_zero_does_nothing(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard('Crop', $set, ['art_crop' => 'https://img.test/art/crop.webp?222']);
        Storage::disk('art-crops')->put("{$set->code}/{$card->id}--222.jpg", 'jpeg');
        Http::fake();

        (new ImageDownloadService)->upgradeToWebp('live', 0);

        Http::assertNothingSent();
        Storage::disk('art-crops')->assertExists("{$set->code}/{$card->id}--222.jpg");
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
