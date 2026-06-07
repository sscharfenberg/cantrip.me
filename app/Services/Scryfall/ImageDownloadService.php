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

        $filename = $this->imageService->buildArtCropFilename($card->id, $timestamp);
        $diskPath = "$setCode/$filename";

        // Already cached with same timestamp — nothing to do
        if (Storage::disk('art-crops')->exists($diskPath)) {
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
            if ($basename === "$uuid.jpg" || str_starts_with($basename, "$uuid--")) {
                Storage::disk('art-crops')->delete($file);
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
            $filename = $this->imageService->buildCardImageFilename($card->id, $timestamp, $index);
            $diskPath = "$setCode/$filename";

            if (Storage::disk('card-images')->exists($diskPath)) {
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
        $suffix = "--$faceIndex.jpg";
        $files = Storage::disk('card-images')->files($setCode);
        foreach ($files as $file) {
            $basename = basename($file);
            if (str_starts_with($basename, "$uuid--") && str_ends_with($basename, $suffix)) {
                Storage::disk('card-images')->delete($file);
            }
        }
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
