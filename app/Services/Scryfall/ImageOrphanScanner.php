<?php

namespace App\Services\Scryfall;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Walks the art-crops and card-images storage disks, identifies files
 * whose UUID prefix is no longer in `default_cards.id`, and returns
 * counts and (optionally) the file list.
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
        $validIds = array_flip(DB::table('default_cards')->pluck('id')->all());

        $result = [
            'art-crops' => $this->scanDisk('art-crops', $validIds, $collectFiles),
            'card-images' => $this->scanDisk('card-images', $validIds, $collectFiles),
        ];

        $result['total'] = [
            'orphans' => $result['art-crops']['orphans'] + $result['card-images']['orphans'],
            'bytes' => $result['art-crops']['bytes'] + $result['card-images']['bytes'],
            'scanned' => $result['art-crops']['scanned'] + $result['card-images']['scanned'],
        ];

        return $result;
    }

    /**
     * @param  array<string, int>  $validIds  array_flip'd UUID → 1 lookup.
     * @return array{files: list<string>, orphans: int, bytes: int, scanned: int}
     */
    private function scanDisk(string $disk, array $validIds, bool $collectFiles): array
    {
        $orphanFiles = [];
        $orphanCount = 0;
        $orphanBytes = 0;
        $files = Storage::disk($disk)->allFiles();

        foreach ($files as $file) {
            $basename = basename($file);
            // Filename: <uuid>(--<timestamp>)?(--<faceIndex>)?.jpg
            // UUID is everything before the first "--" or ".jpg".
            $uuid = preg_replace('/(--.*)$|\.jpg$/', '', $basename);
            if ($uuid === null || $uuid === '' || isset($validIds[$uuid])) {
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
