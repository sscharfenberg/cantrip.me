<?php

namespace App\Services\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\Shadow\ShadowTableRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use stdClass;

/**
 * Handles downloading Scryfall images to the local filesystem.
 *
 * This service only writes to disk — it does not update the database.
 * Path resolution (Scryfall URL → local path) is handled by
 * {@see ResolveImagePathsService}, which checks the disk for cached images.
 *
 * Separation of concerns:
 *   - DefaultCardsService → database (import)
 *   - ImageDownloadService → filesystem (downloading images to disk)
 *   - ResolveImagePathsService → database (URL → local path)
 *
 * A new version of an image is downloaded next to the old one, never in
 * its place. During a shadow build the live rows still point at the old
 * file, and if the download fails the old file is still the best image
 * there is. The superseded version goes once the swap has put rows
 * pointing at its successor live: {@see deleteSuperseded()}, fed by
 * {@see ImageOrphanScanner}.
 *
 * Target-aware: when invoked with `'shadow'`, queries `default_cards__shadow`
 * joined to `sets__shadow` instead of the live tables. That's the path used
 * inside `scryfall:update` — the orchestrator hasn't swapped yet, so the new
 * cards (still carrying `https://…` URLs) live in the shadow tables and need
 * to be discovered there. Querying the live table during a shadow build
 * returns zero rows once everything in live has been previously resolved.
 */
class ImageDownloadService extends ScryfallService
{
    private ScryfallImageService $imageService;

    private FormatService $formatService;

    /**
     * Set folders already known to exist in this run, as "disk:folder".
     *
     * @var array<string, true>
     */
    private array $preparedDirectories = [];

    public function __construct()
    {
        $this->imageService = new ScryfallImageService;
        $this->formatService = new FormatService;
    }

    /**
     * Download all missing or outdated art crop images from Scryfall.
     *
     * Walks every default_card that still has a Scryfall URL in art_crop,
     * checks the local disk for a cached version with the same timestamp,
     * and downloads if missing or outdated. Does not update the database —
     * path resolution happens in DefaultCardsService during the next import.
     *
     * @param  string  $target  `live` (default) or `shadow`.
     */
    public function downloadArtCrops(string $target = 'live'): void
    {
        $start = now();
        $downloaded = 0;
        $skipped = 0;
        $failed = 0;

        Log::channel('scryfall')->notice("begin downloading art crop images (target={$target}).");

        [$cardsTable, $setsTable] = $this->resolveTables($target);

        DB::table("{$cardsTable} as dc")
            ->leftJoin("{$setsTable} as s", 'dc.set_id', '=', 's.id')
            ->select('dc.id', 'dc.name', 'dc.art_crop', 's.code as set_code')
            ->whereNotNull('dc.art_crop')
            ->where('dc.art_crop', 'like', 'https://%')
            ->orderBy('dc.id')
            ->chunkById(200, function ($cards) use (&$downloaded, &$skipped, &$failed) {
                foreach ($cards as $card) {
                    $result = $this->processArtCrop($card);
                    match ($result) {
                        'downloaded' => $downloaded++,
                        'skipped' => $skipped++,
                        'failed' => $failed++,
                    };
                }
            }, 'dc.id', 'id');

        ScryfallRunStats::$artCropsDownloaded = $downloaded;
        ScryfallRunStats::$artCropsSkipped = $skipped;
        ScryfallRunStats::$artCropsFailed = $failed;

        $ms = $start->diffInMilliseconds(now());
        $total = $downloaded + $skipped + $failed;
        Log::channel('scryfall')->notice(
            "finished art crop download: $total cards processed "
            ."($downloaded downloaded, $skipped skipped, $failed failed) "
            .'in '.$this->formatService->formatMs($ms).'.'
        );
    }

    /**
     * Process a single card's art crop image.
     *
     * Checks if the local disk already has the correct version (by timestamp)
     * and downloads it from Scryfall if not, next to any older version.
     * Does not update the database — path resolution happens in
     * ResolveImagePathsService.
     *
     * @return string 'downloaded', 'skipped', or 'failed'
     */
    private function processArtCrop(stdClass $card): string
    {
        $scryfallUrl = $card->art_crop;
        $timestamp = $this->imageService->parseTimestamp($scryfallUrl);
        $setCode = $card->set_code;

        if (! $setCode) {
            Log::channel('scryfall')->warning("card {$card->id} ({$card->name}) has no set, skipping art crop.");

            return 'failed';
        }

        $extension = $this->imageService->imageExtension($scryfallUrl);
        $filename = $this->imageService->buildArtCropFilename($card->id, $timestamp, $extension);
        $diskPath = "$setCode/$filename";

        // Already cached at this version, in this format or another
        if ($this->cachedVersion('art-crops', $diskPath) !== null) {
            return 'skipped';
        }

        try {
            $response = $this->http()->get($scryfallUrl);
            if ($response->successful()) {
                $this->prepareSetDirectory('art-crops', $setCode);
                if ($this->storeAtomically('art-crops', $diskPath, $response->body())) {
                    Log::channel('scryfall')->debug("downloaded art crop for [{$setCode}] {$card->name}.");

                    return 'downloaded';
                }
                Log::channel('scryfall')->error("failed to store art crop for {$card->name}: empty response or write failed.");

                return 'failed';
            }
            Log::channel('scryfall')->error("failed to download art crop for {$card->name}: HTTP {$response->status()}");

            return 'failed';
        } catch (\Exception $e) {
            Log::channel('scryfall')->error("failed to download art crop for {$card->name}: {$e->getMessage()}");

            return 'failed';
        }
    }

    /**
     * Download all missing or outdated card images from Scryfall.
     *
     * Walks every default_card that has Scryfall URLs in card_image_0 or card_image_1,
     * checks the local disk for each face (front/back), and downloads
     * if missing or outdated. Does not update the database.
     *
     * @param  string  $target  `live` (default) or `shadow`.
     */
    public function downloadCardImages(string $target = 'live'): void
    {
        $start = now();
        $downloaded = 0;
        $skipped = 0;
        $failed = 0;

        Log::channel('scryfall')->notice("begin downloading card images (target={$target}).");

        [$cardsTable, $setsTable] = $this->resolveTables($target);

        DB::table("{$cardsTable} as dc")
            ->leftJoin("{$setsTable} as s", 'dc.set_id', '=', 's.id')
            ->select('dc.id', 'dc.name', 'dc.card_image_0', 'dc.card_image_1', 's.code as set_code')
            ->where(function ($q) {
                $q->where('dc.card_image_0', 'like', 'https://%')
                    ->orWhere('dc.card_image_1', 'like', 'https://%');
            })
            ->orderBy('dc.id')
            ->chunkById(200, function ($cards) use (&$downloaded, &$skipped, &$failed) {
                foreach ($cards as $card) {
                    $results = $this->processCardImages($card);
                    $downloaded += $results['downloaded'];
                    $skipped += $results['skipped'];
                    $failed += $results['failed'];
                }
            }, 'dc.id', 'id');

        ScryfallRunStats::$cardImagesDownloaded = $downloaded;
        ScryfallRunStats::$cardImagesSkipped = $skipped;
        ScryfallRunStats::$cardImagesFailed = $failed;

        $ms = $start->diffInMilliseconds(now());
        $total = $downloaded + $skipped + $failed;
        Log::channel('scryfall')->notice(
            "finished card image download: $total images processed "
            ."($downloaded downloaded, $skipped skipped, $failed failed) "
            .'in '.$this->formatService->formatMs($ms).'.'
        );
    }

    /**
     * Process all face images for a single card.
     *
     * Checks card_image_0 and card_image_1 for Scryfall URLs and downloads
     * each face that is missing from the local disk.
     *
     * @return array{downloaded: int, skipped: int, failed: int}
     */
    private function processCardImages(stdClass $card): array
    {
        $counts = ['downloaded' => 0, 'skipped' => 0, 'failed' => 0];
        $setCode = $card->set_code;

        if (! $setCode) {
            Log::channel('scryfall')->warning("card {$card->id} ({$card->name}) has no set, skipping card images.");
            $counts['failed']++;

            return $counts;
        }

        foreach ([0, 1] as $index) {
            $scryfallUrl = $card->{"card_image_$index"};
            if ($scryfallUrl === null || ! str_starts_with($scryfallUrl, 'https://')) {
                continue;
            }

            $timestamp = $this->imageService->parseTimestamp($scryfallUrl);
            $extension = $this->imageService->imageExtension($scryfallUrl);
            $filename = $this->imageService->buildCardImageFilename($card->id, $timestamp, $index, $extension);
            $diskPath = "$setCode/$filename";

            if ($this->cachedVersion('card-images', $diskPath) !== null) {
                $counts['skipped']++;

                continue;
            }

            try {
                $response = $this->http()->get($scryfallUrl);
                if ($response->successful()) {
                    $this->prepareSetDirectory('card-images', $setCode);
                    if ($this->storeAtomically('card-images', $diskPath, $response->body())) {
                        Log::channel('scryfall')->debug("downloaded card image for [{$setCode}] {$card->name} (face $index).");
                        $counts['downloaded']++;
                    } else {
                        Log::channel('scryfall')->error("failed to store card image for {$card->name} (face $index): empty response or write failed.");
                        $counts['failed']++;
                    }
                } else {
                    Log::channel('scryfall')->error("failed to download card image for {$card->name} (face $index): HTTP {$response->status()}");
                    $counts['failed']++;
                }
            } catch (\Exception $e) {
                Log::channel('scryfall')->error("failed to download card image for {$card->name} (face $index): {$e->getMessage()}");
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * Delete the image versions the live cards no longer point at, as
     * found by {@see ImageOrphanScanner::scan()} — run after the swap,
     * against the now-live rows. A file that cannot be deleted is counted
     * and left, see {@see ScryfallService::deleteStaleFile()}.
     *
     * @param  array<string, mixed>  $scan  The scanner's result.
     * @return int Files deleted.
     */
    public function deleteSuperseded(array $scan): int
    {
        $deleted = 0;
        foreach (ImageOrphanScanner::DISKS as $disk) {
            foreach ($scan[$disk]['superseded'] ?? [] as $file) {
                if ($this->deleteStaleFile($disk, $file)) {
                    $deleted++;
                }
            }
        }
        if ($deleted > 0) {
            Log::channel('scryfall')->notice("deleted $deleted superseded image version(s).");
        }

        return $deleted;
    }

    /**
     * Create a set folder with the disk's configured folder permissions
     * (2775, see config/filesystems.php) before the first image goes in.
     *
     * Left to Flysystem, a missing folder is created by a bare mkdir() with
     * no chmod after it, so its mode depends on the umask and on whatever
     * default ACL the parent carries — on prod that combination produced
     * set folders writable for `deploy` alone. The explicit setVisibility()
     * is a chmod, which neither can narrow.
     */
    private function prepareSetDirectory(string $disk, string $setCode): void
    {
        $key = "$disk:$setCode";
        if (isset($this->preparedDirectories[$key])) {
            return;
        }

        $storage = Storage::disk($disk);
        if (! $storage->directoryExists($setCode)) {
            $storage->makeDirectory($setCode);
            $storage->setVisibility($setCode, 'public');
        }
        $this->preparedDirectories[$key] = true;
    }

    /**
     * The cached file holding the same image version as $diskPath — same
     * card, timestamp and face — in any format, or null.
     *
     * The format does not decide whether a cached image is current: when the
     * bulk names the same image version (same timestamp) in another format
     * than the cached one — a JPEG from before Scryfall's 2026-10 WebP
     * switch, or a PNG fetched while a card had no smaller key — the cached
     * file is kept rather than fetched again.
     *
     * @param  string  $disk  `art-crops` or `card-images`.
     * @param  string  $diskPath  The path the current Scryfall URL maps to.
     */
    private function cachedVersion(string $disk, string $diskPath): ?string
    {
        $stem = $this->imageService->stem($diskPath);
        foreach (ScryfallImageService::EXTENSIONS as $extension) {
            if (Storage::disk($disk)->exists("$stem.$extension")) {
                return "$stem.$extension";
            }
        }

        return null;
    }

    /**
     * Resolve the cards + sets table names for the given target. The
     * sets table also needs to track the target — during a shadow build
     * a new card may reference a brand-new set that only exists in
     * `sets__shadow`, so joining against live sets would return null
     * for `set_code` and the card would be skipped as "no set".
     *
     * @return array{0: string, 1: string} [cards-table, sets-table]
     */
    private function resolveTables(string $target): array
    {
        if ($target === 'shadow') {
            return [
                ShadowTableRegistry::shadow('default_cards'),
                ShadowTableRegistry::shadow('sets'),
            ];
        }

        return ['default_cards', 'sets'];
    }
}
