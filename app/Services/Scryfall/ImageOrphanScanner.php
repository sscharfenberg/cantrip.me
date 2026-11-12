<?php

namespace App\Services\Scryfall;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Walks the art-crops and card-images storage disks, identifies files
 * no card can point at, and returns counts and (optionally) the file list.
 *
 * A file is an orphan when its UUID prefix is no longer in
 * `default_cards.id`, or when it lies outside the folder of the card's
 * current set. Images live under `{set code}/`, so when Scryfall moves a
 * printing to another set the next run downloads it into the new folder,
 * and the copy in the old one is left behind: `cleanupOldVersions()` only
 * looks in the current folder.
 *
 * Used by:
 *   - `scryfall:gc-images` — to delete orphans (or dry-run report).
 *   - `scryfall:update`    — to surface a hint at the end of a sync run
 *                            telling the user how many orphans exist
 *                            and the command to clean them.
 */
class ImageOrphanScanner
{
    /**
     * Per-disk orphan summary. Each disk entry is:
     *   - files: list<string> of relative disk paths (only when $collectFiles is true)
     *   - orphans: int count of orphan files
     *   - bytes: int total bytes the orphans occupy
     *   - scanned: int total files on the disk
     *
     * @return array{
     *     art-crops: array{files: list<string>, orphans: int, bytes: int, scanned: int},
     *     card-images: array{files: list<string>, orphans: int, bytes: int, scanned: int},
     *     total: array{orphans: int, bytes: int, scanned: int}
     * }
     */
    public function scan(bool $collectFiles = false): array
    {
        $setFolders = DB::table('default_cards as dc')
            ->join('sets as s', 'dc.set_id', '=', 's.id')
            ->pluck('s.code', 'dc.id')
            ->all();

        $result = [
            'art-crops' => $this->scanDisk('art-crops', $setFolders, $collectFiles),
            'card-images' => $this->scanDisk('card-images', $setFolders, $collectFiles),
        ];

        $result['total'] = [
            'orphans' => $result['art-crops']['orphans'] + $result['card-images']['orphans'],
            'bytes' => $result['art-crops']['bytes'] + $result['card-images']['bytes'],
            'scanned' => $result['art-crops']['scanned'] + $result['card-images']['scanned'],
        ];

        return $result;
    }

    /**
     * @param  array<string, string>  $setFolders  card UUID → code of its current set, the folder its images belong in.
     * @return array{files: list<string>, orphans: int, bytes: int, scanned: int}
     */
    private function scanDisk(string $disk, array $setFolders, bool $collectFiles): array
    {
        $orphanFiles = [];
        $orphanCount = 0;
        $orphanBytes = 0;
        $files = Storage::disk($disk)->allFiles();
        $imageService = new ScryfallImageService;

        foreach ($files as $file) {
            // Path: <set code>/<uuid>(--<timestamp>)?(--<faceIndex>)?.<ext>
            $uuid = $imageService->uuidFromFilename(basename($file));
            if ($uuid === '' || ($setFolders[$uuid] ?? null) === dirname($file)) {
                continue;
            }
            $orphanCount++;
            $orphanBytes += (int) Storage::disk($disk)->size($file);
            if ($collectFiles) {
                $orphanFiles[] = $file;
            }
        }

        return [
            'files' => $orphanFiles,
            'orphans' => $orphanCount,
            'bytes' => $orphanBytes,
            'scanned' => count($files),
        ];
    }
}
