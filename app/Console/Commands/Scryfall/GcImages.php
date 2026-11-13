<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\ImageOrphanScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Garbage-collect orphan art crop and card image files.
 *
 * Walks the art-crops and card-images disks and reports / deletes every
 * file no live card points at ({@see ImageOrphanScanner}):
 *
 *  - files for cards removed from default_cards entirely (Scryfall data
 *    corrections, foreign-language printing reshuffles, etc.);
 *  - files left in a set folder the card no longer belongs to, after
 *    Scryfall moved the printing to another set;
 *  - `.part` files a killed download left behind;
 *  - superseded versions of a card's image. `scryfall:update` deletes
 *    those itself after its swap; they only pile up here when a run
 *    aborted after its image step.
 *
 * Defaults to dry-run (reports counts, deletes nothing). Pass `--prune`
 * to actually delete. Run periodically (cron monthly is plenty).
 */
class GcImages extends Command
{
    /**
     * @var string
     */
    protected $signature = 'scryfall:gc-images
        {--prune : Actually delete the orphan files. Default is dry-run.}
        {--art-crops-only : Only scan the art-crops disk.}
        {--card-images-only : Only scan the card-images disk.}';

    /**
     * @var string
     */
    protected $description = 'Garbage-collect art crop and card image files that no card points at.';

    private FormatService $formatService;

    private ImageOrphanScanner $scanner;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->scanner = new ImageOrphanScanner;
    }

    public function handle(): void
    {
        $start = now();
        $this->info("artisan command 'scryfall:gc-images' started.");
        Log::channel('scryfall')->info("artisan command 'scryfall:gc-images' started.");

        $prune = (bool) $this->option('prune');
        $artOnly = (bool) $this->option('art-crops-only');
        $imagesOnly = (bool) $this->option('card-images-only');

        if ($artOnly && $imagesOnly) {
            $this->error('--art-crops-only and --card-images-only are mutually exclusive.');

            return;
        }

        $scan = $this->scanner->scan(collectFiles: $prune);

        $disks = [];
        if (! $imagesOnly) {
            $disks[] = 'art-crops';
        }
        if (! $artOnly) {
            $disks[] = 'card-images';
        }

        $action = $prune ? 'deleted' : 'would delete';
        $totalDeleted = 0;
        $totalBytes = 0;
        $totalScanned = 0;
        $totalOrphans = 0;
        $totalSuperseded = 0;

        foreach ($disks as $disk) {
            $entry = $scan[$disk];
            $superseded = count($entry['superseded']);
            $totalScanned += $entry['scanned'];
            $totalOrphans += $entry['orphans'];
            $totalSuperseded += $superseded;
            $totalBytes += $entry['bytes'];

            if ($prune) {
                foreach ([...$entry['files'], ...$entry['superseded']] as $file) {
                    Storage::disk($disk)->delete($file);
                    $totalDeleted++;
                    Log::channel('scryfall')->debug("gc-images: deleted $disk/$file.");
                }
            }

            $bytesFormatted = $this->formatService->formatBytes($entry['bytes']);
            $deletedThisDisk = $prune ? $entry['orphans'] + $superseded : 0;
            $this->line("$disk: scanned {$entry['scanned']} files, {$entry['orphans']} orphan(s) ($bytesFormatted), $superseded superseded version(s), $action $deletedThisDisk.");
        }

        $scannedFmt = number_format($totalScanned, 0, ',', '.');
        $orphansFmt = number_format($totalOrphans, 0, ',', '.');
        $supersededFmt = number_format($totalSuperseded, 0, ',', '.');
        $deletedFmt = number_format($totalDeleted, 0, ',', '.');
        $bytesFmt = $this->formatService->formatBytes($totalBytes);

        $this->line('---');
        $this->line("scanned $scannedFmt files, found $orphansFmt orphan(s) taking $bytesFmt and $supersededFmt superseded version(s), $action $deletedFmt file(s).");
        Log::channel('scryfall')->notice("scryfall:gc-images: scanned $scannedFmt files, found $orphansFmt orphans ($bytesFmt) and $supersededFmt superseded versions, $action $deletedFmt.");

        $ms = $start->diffInMilliseconds(now());
        $this->info("artisan command 'scryfall:gc-images' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
