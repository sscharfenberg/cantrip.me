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
 * Path resolution (Scryfall URL → local path) is handled by DefaultCardsService
 * during the next card import, which checks the disk for cached images.
 *
 * Separation of concerns:
 *   - DefaultCardsService → database (import, path resolution)
 *   - ImageDownloadService → filesystem (downloading images to disk)
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

        $staleBefore = ScryfallRunStats::$staleFilesUndeletable;

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
            .$this->undeletableSuffix($staleBefore)
        );
    }

    /**
     * Process a single card's art crop image.
     *
     * Checks if the local disk already has the correct version (by timestamp),
     * downloads from Scryfall if not, and cleans up old versions.
     * Does not update the database — path resolution happens in DefaultCardsService.
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

        // Clean up old versions of this card's art crop
        $this->cleanupOldVersions($setCode, $card->id);

        // Download from Scryfall
        try {
            $response = $this->http()->get($scryfallUrl);
            if ($response->successful()) {
                Storage::disk('art-crops')->put($diskPath, $response->body());
                Log::channel('scryfall')->debug("downloaded art crop for [{$setCode}] {$card->name}.");

                return 'downloaded';
            }
            Log::channel('scryfall')->error("failed to download art crop for {$card->name}: HTTP {$response->status()}");

            return 'failed';
        } catch (\Exception $e) {
            Log::channel('scryfall')->error("failed to download art crop for {$card->name}: {$e->getMessage()}");

            return 'failed';
        }
    }

    /**
     * Delete any previously cached art crop versions for a given card.
     *
     * Finds files matching the card UUID pattern in the set directory
     * and removes them before downloading the new version.
     *
     * @param  string  $setCode  The set code directory.
     * @param  string  $uuid  The card UUID.
     */
    private function cleanupOldVersions(string $setCode, string $uuid): void
    {
        $files = Storage::disk('art-crops')->files($setCode);
        foreach ($files as $file) {
            $basename = basename($file);
            if ($this->imageService->uuidFromFilename($basename) === $uuid) {
                $this->deleteStaleFile('art-crops', $file);
            }
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

        $staleBefore = ScryfallRunStats::$staleFilesUndeletable;

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
            .$this->undeletableSuffix($staleBefore)
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

            $this->cleanupOldCardImages($setCode, $card->id, $index);

            try {
                $response = $this->http()->get($scryfallUrl);
                if ($response->successful()) {
                    Storage::disk('card-images')->put($diskPath, $response->body());
                    Log::channel('scryfall')->debug("downloaded card image for [{$setCode}] {$card->name} (face $index).");
                    $counts['downloaded']++;
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
     * Delete any previously cached card image versions for a given card face.
     *
     * Finds files matching the card UUID and face index pattern in the set
     * directory and removes them before downloading the new version.
     * Only deletes files for the specific face index, leaving other faces intact.
     *
     * @param  string  $setCode  The set code directory.
     * @param  string  $uuid  The card UUID.
     * @param  int  $faceIndex  The face index (0 = front, 1 = back).
     */
    private function cleanupOldCardImages(string $setCode, string $uuid, int $faceIndex): void
    {
        $files = Storage::disk('card-images')->files($setCode);
        foreach ($files as $file) {
            if ($this->imageService->isCardImageFace(basename($file), $uuid, $faceIndex)) {
                $this->deleteStaleFile('card-images', $file);
            }
        }
    }

    /**
     * The cached file holding the same image version as $diskPath — same
     * card, timestamp and face — in any format, or null.
     *
     * The format does not decide whether a cached image is current: a JPEG
     * cached before Scryfall's WebP switch shows the same image as the WebP
     * now in the bulk (both carry the same timestamp), so it is kept rather
     * than fetched again. {@see upgradeToWebp()} replaces those JPEGs a
     * capped number per run.
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
     * Replace up to $limit cached JPEGs with the WebP of the same image.
     *
     * Scryfall serves WebP since 2026-10 and drops most JPEGs on 2027-01-01,
     * while the cache holds ~240,000 JPEGs from before. Those stay valid —
     * {@see cachedVersion()} accepts them — and this pass upgrades them a
     * capped number per run, so the one-time re-fetch (~17 GB) is spread
     * over weeks instead of landing on Scryfall in a single night. Download
     * attempts count against the cap, failed ones included, so a broken
     * state cannot turn into unbounded traffic.
     *
     * Works from the Scryfall URLs, which a row only carries before path
     * resolution — inside `scryfall:update` (target `shadow`) it finds the
     * cards, on an already resolved live table it finds none. The JPEG is
     * deleted once the WebP is on disk, and the resolve step that follows
     * points the card at the WebP before the swap.
     *
     * @param  string  $target  `live` or `shadow`.
     * @param  int  $limit  Most files to download in this run; 0 disables the pass.
     */
    public function upgradeToWebp(string $target, int $limit): void
    {
        if ($limit <= 0) {
            ScryfallRunStats::$webpBackfillComplete = false;

            return;
        }

        $start = now();
        $upgraded = 0;
        $failed = 0;
        $staleBefore = ScryfallRunStats::$staleFilesUndeletable;

        Log::channel('scryfall')->notice("begin replacing cached JPEGs with WebP (target={$target}, limit={$limit}).");

        [$cardsTable, $setsTable] = $this->resolveTables($target);

        foreach ([['art-crops', 'art_crop', null], ['card-images', 'card_image_0', 0], ['card-images', 'card_image_1', 1]] as [$disk, $column, $faceIndex]) {
            if ($upgraded + $failed >= $limit) {
                break;
            }

            DB::table("{$cardsTable} as dc")
                ->join("{$setsTable} as s", 'dc.set_id', '=', 's.id')
                ->select('dc.id', 'dc.name', "dc.{$column} as url", 's.code as set_code')
                ->where("dc.{$column}", 'like', 'https://%.webp%')
                ->orderBy('dc.id')
                ->chunkById(500, function ($cards) use ($disk, $faceIndex, $limit, &$upgraded, &$failed) {
                    foreach ($cards as $card) {
                        match ($this->upgradeOneToWebp($disk, $card, $faceIndex)) {
                            'upgraded' => $upgraded++,
                            'failed' => $failed++,
                            'none' => null,
                        };
                        if ($upgraded + $failed >= $limit) {
                            return false;
                        }
                    }
                }, 'dc.id', 'id');
        }

        ScryfallRunStats::$webpUpgraded = $upgraded;
        ScryfallRunStats::$webpFailed = $failed;
        // Under the cap means every row was looked at: nothing left to upgrade
        // apart from what failed this time.
        ScryfallRunStats::$webpBackfillComplete = $upgraded + $failed < $limit && $failed === 0;

        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->notice(
            "finished replacing cached JPEGs with WebP: $upgraded replaced, $failed failed"
            .(ScryfallRunStats::$webpBackfillComplete ? ', none left' : '')
            .' in '.$this->formatService->formatMs($ms).'.'
            .$this->undeletableSuffix($staleBefore)
        );
    }

    /**
     * Replace one cached JPEG with the WebP its Scryfall URL now points at.
     *
     * @param  int|null  $faceIndex  Card image face, or null for an art crop.
     * @return 'upgraded'|'failed'|'none' `none` when nothing is cached yet
     *                                    (the download pass handles that) or the cached file is a WebP already.
     */
    private function upgradeOneToWebp(string $disk, stdClass $card, ?int $faceIndex): string
    {
        $timestamp = $this->imageService->parseTimestamp($card->url);
        $filename = $faceIndex === null
            ? $this->imageService->buildArtCropFilename($card->id, $timestamp, 'webp')
            : $this->imageService->buildCardImageFilename($card->id, $timestamp, $faceIndex, 'webp');
        $webpPath = "{$card->set_code}/$filename";

        $cached = $this->cachedVersion($disk, $webpPath);
        if ($cached === null || $cached === $webpPath) {
            return 'none';
        }

        try {
            $response = $this->http()->get($card->url);
            if (! $response->successful()) {
                Log::channel('scryfall')->error("failed to fetch WebP for {$card->name} ($disk): HTTP {$response->status()}");

                return 'failed';
            }
            Storage::disk($disk)->put($webpPath, $response->body());
            $this->deleteStaleFile($disk, $cached);

            return 'upgraded';
        } catch (\Exception $e) {
            Log::channel('scryfall')->error("failed to fetch WebP for {$card->name} ($disk): {$e->getMessage()}");

            return 'failed';
        }
    }

    /**
     * Report stale files this step could not delete, if any.
     *
     * Reads the run-wide counter rather than a local one so the number also
     * covers deletes attempted from other services in the same run; the
     * baseline is captured per step so each step reports only its own.
     *
     * @param  int  $staleBefore  Counter value before this step ran.
     */
    private function undeletableSuffix(int $staleBefore): string
    {
        $undeletable = ScryfallRunStats::$staleFilesUndeletable - $staleBefore;

        if ($undeletable === 0) {
            return '';
        }

        return " could not delete $undeletable stale file(s) — check filesystem permissions.";
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
