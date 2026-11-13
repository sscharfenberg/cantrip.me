<?php

namespace App\Services\Scryfall;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Walks the art-crops and card-images storage disks and sorts out the
 * files no live card points at, in two kinds.
 *
 * **Orphans** — files no card can point at: the UUID prefix is no longer
 * in `default_cards.id`; the file lies outside the folder of the card's
 * current set (images live under `{set code}/`, so when Scryfall moves a
 * printing to another set the next run downloads it into the new folder
 * and the copy in the old one is left behind); the card no longer has
 * that image at all (its column is empty — a face it lost, an art crop
 * Scryfall dropped); or it is a `.part` file a killed download left.
 *
 * **Superseded versions** — an older version of an image whose newer
 * version the card's live row names. A new version is downloaded next to
 * the old one ({@see ImageDownloadService}), so the old one is needed
 * until the swap puts the rows pointing at the new one live. Only a file
 * *older* than the named one counts, and only while the named one is on
 * disk: a newer download that is not live yet (a run aborted before its
 * swap, or a sync still building) is kept, and so is a card's last image.
 * A column still holding a Scryfall URL (the download failed) keeps every
 * version of that image.
 *
 * Used by:
 *   - `scryfall:update`    — deletes the superseded versions after the
 *                            swap and surfaces a hint with the prune
 *                            command if orphans exist.
 *   - `scryfall:gc-images` — to delete both kinds (or dry-run report).
 */
class ImageOrphanScanner
{
    /** @var list<string> */
    public const DISKS = ['art-crops', 'card-images'];

    /** Marks an image whose column still holds a Scryfall URL. */
    private const UNRESOLVED = '';

    /**
     * Per-disk summary. Each disk entry is:
     *   - files: list<string> of orphan paths (only when $collectFiles is true)
     *   - orphans: int count of orphan files
     *   - bytes: int total bytes the orphans occupy
     *   - superseded: list<string> of superseded versions (always collected)
     *   - scanned: int total files on the disk
     *
     * @return array{
     *     art-crops: array{files: list<string>, orphans: int, bytes: int, superseded: list<string>, scanned: int},
     *     card-images: array{files: list<string>, orphans: int, bytes: int, superseded: list<string>, scanned: int},
     *     total: array{orphans: int, bytes: int, superseded: int, scanned: int}
     * }
     */
    public function scan(bool $collectFiles = false): array
    {
        [$folders, $current] = $this->liveImages();

        $result = [
            'art-crops' => $this->scanDisk('art-crops', $folders, $current['art-crops'], $collectFiles),
            'card-images' => $this->scanDisk('card-images', $folders, $current['card-images'], $collectFiles),
        ];

        $result['total'] = [
            'orphans' => $result['art-crops']['orphans'] + $result['card-images']['orphans'],
            'bytes' => $result['art-crops']['bytes'] + $result['card-images']['bytes'],
            'superseded' => count($result['art-crops']['superseded']) + count($result['card-images']['superseded']),
            'scanned' => $result['art-crops']['scanned'] + $result['card-images']['scanned'],
        ];

        return $result;
    }

    /**
     * Every live card's set folder, and per disk the file each image
     * column names, keyed `{uuid}` (art crop) or `{uuid}--{face}` (card
     * face), as a path on the disk — or {@see UNRESOLVED} while the column
     * holds a Scryfall URL. An empty column has no entry. Flat maps of
     * strings: about 120k cards are held at once.
     *
     * @return array{array<string, string>, array{art-crops: array<string, string>, card-images: array<string, string>}}
     */
    private function liveImages(): array
    {
        $folders = [];
        $current = ['art-crops' => [], 'card-images' => []];
        $rows = DB::table('default_cards as dc')
            ->join('sets as s', 'dc.set_id', '=', 's.id')
            ->select('dc.id', 's.code', 'dc.art_crop', 'dc.card_image_0', 'dc.card_image_1')
            ->lazyById(5000, 'dc.id', 'id');

        foreach ($rows as $row) {
            $folders[$row->id] = $row->code;
            $this->remember($current['art-crops'], $row->id, $row->art_crop, '/art-crops/');
            $this->remember($current['card-images'], "$row->id--0", $row->card_image_0, '/card-images/');
            $this->remember($current['card-images'], "$row->id--1", $row->card_image_1, '/card-images/');
        }

        return [$folders, $current];
    }

    /**
     * @param  array<string, string>  $map
     */
    private function remember(array &$map, string $key, ?string $column, string $prefix): void
    {
        if ($column === null || $column === '') {
            return;
        }
        $map[$key] = str_starts_with($column, $prefix) ? substr($column, strlen($prefix)) : self::UNRESOLVED;
    }

    /**
     * @param  array<string, string>  $folders  card UUID → code of its current set, the folder its images belong in.
     * @param  array<string, string>  $current  image key → path the live column names, see {@see liveImages()}.
     * @return array{files: list<string>, orphans: int, bytes: int, superseded: list<string>, scanned: int}
     */
    private function scanDisk(string $disk, array $folders, array $current, bool $collectFiles): array
    {
        $orphanFiles = [];
        $orphanCount = 0;
        $orphanBytes = 0;
        $superseded = [];
        $files = Storage::disk($disk)->allFiles();
        $onDisk = array_flip($files);
        $imageService = new ScryfallImageService;
        $cardFaces = $disk === 'card-images';

        foreach ($files as $file) {
            // Path: <set code>/<uuid>(--<timestamp>)?(--<faceIndex>)?.<ext>
            $basename = basename($file);
            $uuid = $imageService->uuidFromFilename($basename);
            if ($uuid === '') {
                continue;
            }

            $named = null;
            if (($folders[$uuid] ?? null) === dirname($file)) {
                $named = $current[$this->imageKey($basename, $uuid, $cardFaces, $imageService)] ?? null;
            }

            if ($named === null) {
                $orphanCount++;
                $orphanBytes += (int) Storage::disk($disk)->size($file);
                if ($collectFiles) {
                    $orphanFiles[] = $file;
                }

                continue;
            }

            if ($named !== self::UNRESOLVED
                && $named !== $file
                && isset($onDisk[$named])
                && $imageService->versionFromFilename($basename, $cardFaces) < $imageService->versionFromFilename(basename($named), $cardFaces)
            ) {
                $superseded[] = $file;
            }
        }

        return [
            'files' => $orphanFiles,
            'orphans' => $orphanCount,
            'bytes' => $orphanBytes,
            'superseded' => $superseded,
            'scanned' => count($files),
        ];
    }

    /**
     * The key {@see liveImages()} stores the image $basename is a version
     * of under: the UUID for an art crop, `{uuid}--{face}` for a card face.
     */
    private function imageKey(string $basename, string $uuid, bool $cardFaces, ScryfallImageService $imageService): string
    {
        if (! $cardFaces) {
            return $uuid;
        }
        foreach ([0, 1] as $faceIndex) {
            if ($imageService->isCardImageFace($basename, $uuid, $faceIndex)) {
                return "$uuid--$faceIndex";
            }
        }

        return "$uuid--?";
    }
}
