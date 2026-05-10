<?php

namespace App\Services\Scryfall;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves Scryfall image URLs to local filesystem paths.
 *
 * After ImageDownloadService has downloaded images to disk, this service
 * updates database records to point at the local cache instead of Scryfall.
 *
 * Separation of concerns:
 *   - ImageDownloadService  → filesystem (downloading images to disk)
 *   - ResolveImagePathsService → database (URL → local path resolution)
 */
class ResolveImagePathsService extends ScryfallService
{
    /**
     * Cached directory listings indexed by "disk:setCode".
     * Each entry maps UUID to an array of relative disk paths for that card.
     *
     * @var array<string, array<string, string[]>>
     */
    private array $fileIndex = [];

    private string $defaultCardsTable = 'default_cards';

    private string $setsTable = 'sets';

    /**
     * Switch the service into live or shadow mode. Subsequent calls to
     * {@see resolveArtCropPaths()} and {@see resolveCardImagePaths()}
     * UPDATE the corresponding table.
     */
    public function useTarget(bool $shadow): void
    {
        $this->defaultCardsTable = $this->tableName('default_cards', $shadow);
        $this->setsTable = $this->tableName('sets', $shadow);
    }

    /**
     * Build a `set_id → set.code` lookup once per resolve pass so the
     * card iteration doesn't need an eager-load (Eloquent relationships
     * are bound to live table names, which would cross-contaminate
     * shadow runs).
     *
     * @return array<string, string>
     */
    private function loadSetCodes(): array
    {
        return DB::table($this->setsTable)->pluck('code', 'id')->all();
    }

    /**
     * Build or retrieve the file index for a given disk + set directory.
     *
     * Lists all files once per set per disk, then indexes them by UUID prefix
     * for O(1) lookups. The index maps each UUID to all its matching files.
     *
     * @param  string  $disk  The Storage disk name.
     * @param  string  $setCode  The set code directory.
     * @return array<string, string[]> UUID → list of relative disk paths.
     */
    private function getFileIndex(string $disk, string $setCode): array
    {
        $cacheKey = "$disk:$setCode";
        if (isset($this->fileIndex[$cacheKey])) {
            return $this->fileIndex[$cacheKey];
        }

        $index = [];
        foreach (Storage::disk($disk)->files($setCode) as $file) {
            $basename = basename($file);
            // Extract UUID: everything before the first "--" or ".jpg"
            $uuid = preg_replace('/(--.*)$|\.jpg$/', '', $basename);
            if ($uuid) {
                $index[$uuid][] = $file;
            }
        }

        $this->fileIndex[$cacheKey] = $index;

        return $index;
    }

    /**
     * Find a file on disk matching a UUID prefix for an art crop.
     *
     * Art crop filenames: {uuid}--{timestamp}.jpg or {uuid}.jpg
     * A card has exactly one art crop, so any file starting with the UUID is a match.
     *
     * @param  string  $disk  The Storage disk name.
     * @param  string  $setCode  The set code directory.
     * @param  string  $uuid  The card UUID.
     * @return string|null The relative disk path if found, null otherwise.
     */
    private function findArtCropOnDisk(string $disk, string $setCode, string $uuid): ?string
    {
        $index = $this->getFileIndex($disk, $setCode);

        return $index[$uuid][0] ?? null;
    }

    /**
     * Find a file on disk matching a UUID and face index for a card image.
     *
     * Card image filenames: {uuid}--{timestamp}--{faceIndex}.jpg or {uuid}--{faceIndex}.jpg
     * Must match the specific face index suffix to avoid returning face 0's
     * file when looking for face 1 (and vice versa).
     *
     * @param  string  $disk  The Storage disk name.
     * @param  string  $setCode  The set code directory.
     * @param  string  $uuid  The card UUID.
     * @param  int  $faceIndex  The face index (0 = front, 1 = back).
     * @return string|null The relative disk path if found, null otherwise.
     */
    private function findCardImageOnDisk(string $disk, string $setCode, string $uuid, int $faceIndex): ?string
    {
        $index = $this->getFileIndex($disk, $setCode);
        $files = $index[$uuid] ?? [];
        $suffix = "--$faceIndex.jpg";
        foreach ($files as $file) {
            if (str_ends_with(basename($file), $suffix)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Update art_crop paths for default cards that still point to Scryfall URLs
     * but already have a cached image on disk.
     *
     * Instead of building the expected filename from the URL timestamp, scans
     * the disk for any file matching the card UUID. This makes resolution
     * resilient to timestamp mismatches between the bulk data and disk.
     *
     * @return int Number of paths resolved.
     */
    public function resolveArtCropPaths(): int
    {
        $this->fileIndex = [];
        $resolved = 0;
        $setCodes = $this->loadSetCodes();
        $defaultCardsTable = $this->defaultCardsTable;

        // chunkById() (NOT chunk()) is required when the iteration UPDATEs
        // the rows it's reading — chunk()'s OFFSET pagination silently
        // skips rows once the WHERE result-set shrinks under updates.
        DB::table($defaultCardsTable)
            ->whereNotNull('art_crop')
            ->where('art_crop', 'like', 'https://%')
            ->select('id', 'set_id')
            ->chunkById(500, function ($cards) use (&$resolved, $setCodes, $defaultCardsTable) {
                foreach ($cards as $card) {
                    $setCode = $setCodes[$card->set_id] ?? null;
                    if (! $setCode) {
                        continue;
                    }

                    $diskPath = $this->findArtCropOnDisk('art-crops', $setCode, $card->id);
                    if ($diskPath) {
                        DB::table($defaultCardsTable)
                            ->where('id', $card->id)
                            ->update(['art_crop' => "/art-crops/$diskPath"]);
                        $resolved++;
                    }
                }
            });

        return $resolved;
    }

    /**
     * Update card_image_0 / card_image_1 for default cards that still have
     * Scryfall URLs but already have cached images on disk.
     *
     * Scans the disk for a file matching the card UUID and the specific face
     * index suffix, so face 0 and face 1 files are never confused.
     *
     * @return int Number of resolved image paths.
     */
    public function resolveCardImagePaths(): int
    {
        $this->fileIndex = [];
        $resolved = 0;
        $setCodes = $this->loadSetCodes();
        $defaultCardsTable = $this->defaultCardsTable;

        foreach ([0, 1] as $index) {
            $column = "card_image_$index";

            // chunkById() (NOT chunk()) — see resolveArtCropPaths() comment
            // for why OFFSET pagination breaks here.
            DB::table($defaultCardsTable)
                ->whereNotNull($column)
                ->where($column, 'like', 'https://%')
                ->select('id', 'set_id', $column)
                ->chunkById(500, function ($cards) use (&$resolved, $column, $index, $setCodes, $defaultCardsTable) {
                    foreach ($cards as $card) {
                        $setCode = $setCodes[$card->set_id] ?? null;
                        if (! $setCode) {
                            continue;
                        }

                        $diskPath = $this->findCardImageOnDisk('card-images', $setCode, $card->id, $index);
                        if ($diskPath) {
                            DB::table($defaultCardsTable)
                                ->where('id', $card->id)
                                ->update([$column => "/card-images/$diskPath"]);
                            $resolved++;
                        }
                    }
                });
        }

        return $resolved;
    }
}
