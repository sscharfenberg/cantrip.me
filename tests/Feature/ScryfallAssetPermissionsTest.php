<?php

namespace Tests\Feature;

use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Services\Scryfall\ImageDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Scryfall asset folders are shared by `deploy` (the nightly sync) and
 * `www-data`, and partly by prod and staging, so whatever the sync writes
 * must be group-writable: files 664, folders 2775.
 *
 * Flysystem's own defaults are 644 / 755, applied by chmod (files) or as
 * the mkdir() mode (folders), so on prod every file and new set folder the
 * sync wrote was writable for `deploy` alone. The disks are faked with
 * their real configuration from config/filesystems.php — `Storage::fake()`
 * drops it otherwise — and the umask is forced to prod's 022, so a pass
 * here is the configuration and the code, not a lenient test machine.
 *
 * Skipped on the staging suite — uses RefreshDatabase.
 */
class ScryfallAssetPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const DISKS = ['set', 'symbol', 'art-crops', 'card-images'];

    private int $umask;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Uses RefreshDatabase; never run against MariaDB.');
        }
        $this->umask = umask(0022);
        foreach (self::DISKS as $disk) {
            Storage::fake($disk, config("filesystems.disks.$disk"));
        }
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        parent::tearDown();
    }

    #[Test]
    public function every_scryfall_asset_disk_writes_group_writable_files(): void
    {
        foreach (self::DISKS as $disk) {
            Storage::disk($disk)->put('probe.svg', 'x');

            $this->assertSame('664', $this->mode($disk, 'probe.svg'), "a file on the '$disk' disk");
        }
    }

    #[Test]
    public function a_downloaded_image_lands_group_writable_in_a_group_writable_set_folder(): void
    {
        $set = $this->makeSet();
        $this->makeDefaultCard('Fresh', $set, [
            'art_crop' => 'https://img.test/art/fresh.webp?111',
            'card_image_0' => 'https://img.test/display/fresh-0.webp?111',
        ]);
        Http::fake(['*' => Http::response('webp-bytes', 200)]);

        $service = new ImageDownloadService;
        $service->downloadArtCrops();
        $service->downloadCardImages();

        foreach (['art-crops', 'card-images'] as $disk) {
            $this->assertSame('2775', $this->mode($disk, $set->code), "the new set folder on '$disk'");
            $files = Storage::disk($disk)->files($set->code);
            $this->assertCount(1, $files);
            $this->assertSame('664', $this->mode($disk, $files[0]), "the image on '$disk'");
        }
    }

    #[Test]
    public function a_backfilled_webp_is_group_writable(): void
    {
        $set = $this->makeSet();
        $card = $this->makeDefaultCard('Upgraded', $set, ['art_crop' => 'https://img.test/art/upgraded.webp?222']);
        Storage::disk('art-crops')->put("{$set->code}/{$card->id}--222.jpg", 'jpeg');
        Http::fake(['*' => Http::response('webp-bytes', 200)]);

        (new ImageDownloadService)->upgradeToWebp('live', 10);

        $this->assertSame('664', $this->mode('art-crops', "{$set->code}/{$card->id}--222.webp"));
    }

    /**
     * Permission bits of a path on a faked disk, in octal, setuid/setgid
     * included ("2775", "664").
     */
    private function mode(string $disk, string $path): string
    {
        clearstatcache();

        return decoct(fileperms(Storage::disk($disk)->path($path)) & 07777);
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
