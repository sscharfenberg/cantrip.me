<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\ImageDownloadService;
use App\Services\Scryfall\ImageOrphanScanner;
use App\Services\Scryfall\ScryfallFailureAlert;
use App\Services\Scryfall\ScryfallRunStats;
use App\Services\Scryfall\SetsService;
use App\Services\Scryfall\Shadow\ShadowSwapper;
use App\Services\Scryfall\Shadow\ShadowTableRegistry;
use App\Services\Scryfall\Shadow\ShadowTableService;
use App\Services\Scryfall\Shadow\ShadowValidationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class UpdateEverything extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:update
        {--allow-shrink : Swap even if a table lost more rows than the pre-swap check allows — for a deliberate change to what is imported}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update every scryfall resource via the shadow-table swap flow.';

    private FormatService $formatService;

    private ShadowTableService $shadow;

    private ShadowValidationService $validation;

    private ImageOrphanScanner $imageOrphanScanner;

    private ImageDownloadService $imageDownloads;

    private SetsService $sets;

    private ShadowSwapper $swapper;

    private ScryfallFailureAlert $alert;

    /**
     * Section → list of [label, value] tuples. Built up across the run
     * and emitted as the end-of-run summary block.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private array $summary = [];

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->shadow = new ShadowTableService;
        $this->validation = new ShadowValidationService;
        $this->imageOrphanScanner = new ImageOrphanScanner;
        $this->imageDownloads = new ImageDownloadService;
        $this->sets = new SetsService;
        $this->swapper = new ShadowSwapper($this->shadow);
        $this->alert = new ScryfallFailureAlert;
    }

    /**
     * Push one row into the end-of-run summary, grouped by section header.
     */
    private function pushSummary(string $section, string $label, string $value): void
    {
        $this->summary[$section][] = [$label, $value];
    }

    /**
     * Format an integer with thousands separators.
     */
    private function fmtCount(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    /**
     * Emit the accumulated summary as a formatted block. No-op if empty.
     *
     * The same block is written to the console (for terminal runs) and to
     * the scryfall log channel as a single multi-line entry (for cron runs,
     * where console output is discarded) — logging it in one call keeps the
     * dividers intact instead of prefixing every line with a timestamp.
     */
    private function emitSummary(): void
    {
        if ($this->summary === []) {
            return;
        }
        $bar = str_repeat('═', 64);
        $lines = [$bar, ' scryfall:update summary', $bar];
        foreach ($this->summary as $section => $rows) {
            $lines[] = '';
            $lines[] = $section;
            foreach ($rows as [$label, $value]) {
                $lines[] = sprintf('  %-34s %s', $label, $value);
            }
        }
        $lines[] = $bar;

        $this->newLine();
        foreach ($lines as $line) {
            $this->line($line);
        }
        $this->newLine();

        Log::channel('scryfall')->info(implode("\n", $lines));
    }

    /**
     * Sleep for a configured amount of seconds, then return the idled seconds.
     */
    private function sleep(): int
    {
        $duration = 2;
        sleep($duration);

        return $duration;
    }

    /**
     * Run a sub-command and send a failure alert if it throws or exits non-zero.
     *
     * On failure: logs the exception to the scryfall channel, fires the
     * ScryfallUpdateFailedNotification (mail + Discord, see
     * {@see ScryfallFailureAlert}), then re-throws.
     * The orchestrator's outer flow does not bring the site down, so any
     * mid-flight failure leaves the live tables completely untouched —
     * users see the previous-good dataset throughout.
     *
     * @throws Throwable
     */
    private function runStep(string $command, bool $shadow = false): void
    {
        $args = $shadow ? ['--target' => 'shadow'] : [];
        try {
            $exitCode = $this->call($command, $args);
            if ($exitCode !== self::SUCCESS) {
                throw new RuntimeException("artisan command '$command' returned non-zero exit code $exitCode.");
            }
        } catch (Throwable $e) {
            Log::channel('scryfall')->error("artisan command '$command' failed.", [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->alert->send($command, $e);

            throw $e;
        }
    }

    /**
     * Run an in-process orchestrator step (cleanup, createLike, FK
     * handling, swap, dropRetired, validation) with the same alerting
     * contract as {@see runStep()} for sub-commands.
     *
     * @throws Throwable
     */
    private function runOrchestratorStep(string $name, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::channel('scryfall')->error("orchestrator step '$name' failed.", [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->alert->send("orchestrator:$name", $e);

            throw $e;
        }
    }

    /**
     * Run the FK orphan validation. Throws on any violation; the alert
     * payload includes the orphan details so manual recovery can begin
     * with `SELECT * FROM <source> WHERE <fk> NOT IN (SELECT id FROM <target>)`.
     *
     * On abort the *__shadow tables stay on disk for inspection per the
     * agreed orphan-abort policy. Cleanup-on-startup drops them next run.
     */
    private function validateOrAbort(): void
    {
        $orphans = $this->validation->findOrphans();
        if ($orphans === []) {
            Log::channel('scryfall')->info('shadow validation: every FK relation resolves cleanly.');

            return;
        }

        $summary = [];
        foreach ($orphans as $violation) {
            $summary[] = "{$violation['source']}.{$violation['fk']} → {$violation['target']}: {$violation['orphans']} orphan(s)";
        }
        throw new RuntimeException(
            'shadow validation aborted the swap — '.count($orphans).' FK violation(s): '.implode('; ', $summary)
        );
    }

    /**
     * Abort the swap when a shadow table came out empty or implausibly
     * small, see {@see ShadowValidationService::findShrunkTables()}.
     * `--allow-shrink` turns the abort into a warning for a run that
     * shrinks a table on purpose.
     */
    private function validateRowCountsOrAbort(): void
    {
        $shrunk = $this->validation->findShrunkTables();
        if ($shrunk === []) {
            Log::channel('scryfall')->info('shadow validation: every table kept a plausible row count.');

            return;
        }

        $summary = [];
        foreach ($shrunk as $table) {
            $summary[] = "{$table['table']}: {$this->fmtCount($table['shadow'])} rows, live has {$this->fmtCount($table['live'])}";
        }
        $message = count($shrunk).' table(s) shrank: '.implode('; ', $summary);

        if ($this->option('allow-shrink')) {
            Log::channel('scryfall')->warning("shadow validation: $message — swapping anyway (--allow-shrink).");
            $this->warn("shadow validation: $message — swapping anyway (--allow-shrink).");

            return;
        }

        throw new RuntimeException(
            "shadow validation aborted the swap — $message. If that is intended, run `php artisan scryfall:update --allow-shrink`."
        );
    }

    /**
     * Execute the shadow-table update flow.
     *
     * Sequence:
     *   1. Cleanup any leftover *__shadow / *__retired from a crashed prior run.
     *   2. createLike for every truncate-rebuild scryfall table — empty shadows.
     *   3. Run each import step in shadow mode. Order:
     *        - bulk first (writes download URIs to bulk_data__shadow)
     *        - sets, symbols (no bulk_data dependency)
     *        - oracle (reads bulk_data__shadow)
     *        - oracle-tags (UPDATEs oracle_cards__shadow)
     *        - translations (streams all_cards from Scryfall;
     *          reads oracle_cards__shadow + oracle_card_faces__shadow
     *          for FK validation; writes
     *          oracle_card_translations__shadow +
     *          oracle_card_face_translations__shadow)
     *        - default_cards (reads bulk_data__shadow + oracle_cards__shadow)
     *        - rulings (reads bulk_data__shadow + oracle_cards__shadow)
     *   4. Image download (disk-only, no shadow concern).
     *   5. Resolve URLs → local paths on default_cards__shadow.
     *   6. Validate every FK relation against the shadow build.
     *      Includes user-data FKs (deck_cards, card_stacks) — Scryfall
     *      removing a card any user references aborts here.
     *   7. Validate row counts: no shadow table may come out empty or
     *      implausibly small (`--allow-shrink` overrides).
     *   8. Swap ({@see ShadowSwapper}): capture and drop every FK into the
     *      swap tables, atomic multi-table RENAME (old → __retired,
     *      shadows → live), re-add the FKs, drop the __retired tables.
     *   9. Delete the image versions and set icons the swap superseded —
     *      kept until now because the old live rows pointed at them — and
     *      scan for orphaned image files (reported, never deleted here).
     *  10. Refresh welcome-page Scryfall stats cache from the new live data.
     *
     * Before any of it, FK constraints a crashed earlier run dropped and
     * never put back are re-added.
     *
     * No maintenance mode. The DB churn is invisible to users — they
     * read the previous-good live tables right up until the millisecond
     * the RENAME swaps them, and immediately after read the new dataset.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        $start = now();
        $waitTime = 0;
        $this->summary = [];
        ScryfallRunStats::reset();

        $this->info("artisan command 'scryfall:update' started.");
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:update' started.");
        Log::channel('scryfall')->info('=======================================================');

        // 1. Repair what a crashed prior run left behind: FK constraints
        //    it dropped around its swap and never re-added, then its
        //    leftover tables.
        $restoredFks = ['restored' => 0, 'failed' => []];
        $this->runOrchestratorStep('restoreForeignKeys', function () use (&$restoredFks): void {
            $restoredFks = $this->shadow->restorePendingForeignKeys();
        });
        if ($restoredFks['restored'] > 0) {
            $this->warn("shadow cleanup: re-added {$restoredFks['restored']} FK constraints an earlier run dropped and never restored.");
        }
        if ($restoredFks['failed'] !== []) {
            $message = 'an earlier run dropped FK constraints that could not be re-added — add them back by hand: '.implode('; ', $restoredFks['failed']);
            $this->error("shadow cleanup: $message");
            $this->alert->warn('orchestrator:restoreForeignKeys', $message);
        }
        $this->runOrchestratorStep('cleanup', fn () => $this->shadow->cleanup());
        $this->info('shadow cleanup: dropped any leftover __shadow / __retired tables.');

        // 2. Create empty shadow tables for every registered scryfall table.
        $this->runOrchestratorStep('createLike', function (): void {
            foreach (ShadowTableRegistry::TABLES as $table) {
                $this->shadow->createLike($table);
            }
        });
        $this->info('shadow build: created '.count(ShadowTableRegistry::TABLES).' empty shadow tables.');

        // 3. Run every import step in shadow mode.
        // bulk first — its rows are read by oracle/default_cards/rulings.
        $this->runStep('scryfall:bulk', shadow: true);
        $waitTime += $this->sleep();
        $this->runStep('scryfall:sets', shadow: true);
        $waitTime += $this->sleep();
        $this->runStep('scryfall:symbols', shadow: true);
        $waitTime += $this->sleep();
        $this->runStep('scryfall:oracle', shadow: true);
        $waitTime += $this->sleep();
        // oracle-tags: hits the Scryfall search endpoint and UPDATEs
        // oracle_cards__shadow (mld, fetch_pattern, land_search_pattern
        // columns). 1s pacing
        // is enforced inside the service.
        $this->runStep('scryfall:oracle-tags', shadow: true);
        $waitTime += $this->sleep();
        // translations: streams the ~372 MB gzipped all_cards bulk
        // straight from Scryfall (no on-disk file). Depends on
        // oracle_cards__shadow + oracle_card_faces__shadow
        // being populated for FK validation, hence ordered after
        // oracle. Independent of default_cards / rulings / images so
        // it can run before or after them; placed here for cleanly
        // grouping all oracle-derived imports before printing-level
        // ones.
        $this->runStep('scryfall:translations', shadow: true);
        $waitTime += $this->sleep();
        $this->runStep('scryfall:default_cards', shadow: true);
        $waitTime += $this->sleep();
        // rulings runs after oracle so its FK lookup against
        // oracle_cards__shadow finds every parent.
        $this->runStep('scryfall:rulings', shadow: true);
        $waitTime += $this->sleep();

        // 4. Image download — queries default_cards__shadow joined to
        //    sets__shadow to discover the newly-inserted rows whose
        //    image columns still hold Scryfall URLs. Targeting live
        //    here would return zero rows (live's already resolved from
        //    a previous swap), leaving the new cards in the next live
        //    swap pointing at the Scryfall CDN with no local cache.
        $this->runStep('scryfall:images', shadow: true);
        $waitTime += $this->sleep();

        // 5. Resolve image URLs → local paths on default_cards__shadow.
        $this->runStep('scryfall:resolve-paths', shadow: true);

        // 6. Validate FK integrity against the shadow build (LEFT JOINs;
        //    no FK constraints on the shadow tables yet — that's intentional).
        $this->runOrchestratorStep('validateOrphans', fn () => $this->validateOrAbort());
        $this->info('shadow validation: passed (every FK relation resolves cleanly).');

        // 7. No table may come out empty or implausibly small — the orphan
        //    check above only covers tables user data points into.
        $this->runOrchestratorStep('validateRowCounts', fn () => $this->validateRowCountsOrAbort());
        $this->info('shadow validation: passed (no table emptied or implausibly shrunk).');

        // 8. Swap the shadow build live. Every FK that references a swap
        //    table (internal scryfall FKs AND user-data FKs from
        //    deck_cards / card_stacks / etc.) is dropped first, because
        //    MariaDB auto-rotates FK references when the parent table is
        //    renamed, and re-added after — see ShadowSwapper for how none
        //    of them can get lost in between.
        $capturedFks = $this->swapper->swapIntoLive(
            fn (string $name, callable $work) => $this->runOrchestratorStep($name, $work)
        );
        $this->info('shadow swap: atomic RENAME completed (live = previous shadow); '
            .count(ShadowTableRegistry::TABLES).' __retired tables dropped, '
            ."$capturedFks FK constraints dropped around it and re-added.");

        // 9. Scan the image disks against the now-live default_cards.
        //    Superseded versions (an older image of a card that now points
        //    at a newer one) and superseded set icons are deleted — they
        //    were only kept so the old live rows had something to show
        //    until the swap — and so are .part leftovers on the SVG disks.
        //    Orphans are reported only: surface a hint with the prune
        //    command if any are found, never delete them from the auto
        //    flow. Housekeeping: the new data is already live, so a
        //    failure here alerts and the run carries on to the cache.
        $imageOrphans = [
            'art-crops' => ['files' => [], 'orphans' => 0, 'bytes' => 0, 'superseded' => [], 'scanned' => 0],
            'card-images' => ['files' => [], 'orphans' => 0, 'bytes' => 0, 'superseded' => [], 'scanned' => 0],
            'total' => ['orphans' => 0, 'bytes' => 0, 'superseded' => 0, 'scanned' => 0],
        ];
        try {
            $imageOrphans = $this->imageOrphanScanner->scan();
            ScryfallRunStats::$supersededFilesDeleted = $this->imageDownloads->deleteSuperseded($imageOrphans)
                + $this->sets->deleteSupersededIcons()
                + $this->sets->deletePartialDownloads('set')
                + $this->sets->deletePartialDownloads('symbol');
        } catch (Throwable $e) {
            Log::channel('scryfall')->error("post-swap cleanup failed: {$e->getMessage()}", ['exception' => $e::class]);
            $this->error("post-swap cleanup failed: {$e->getMessage()}");
            $this->alert->warn('orchestrator:pruneSuperseded', "the swap succeeded, but deleting superseded files failed: {$e->getMessage()}");
        }

        // 10. Refresh the welcome-page Scryfall stats cache against the
        //     now-live data so the next visitor doesn't pay the
        //     recursive `find` + `du` cost.
        $this->runStep('scryfall:cache');
        if ($imageOrphans['total']['orphans'] > 0) {
            $this->newLine();
            $this->warn(sprintf(
                'image GC: found %s orphan file(s) totaling %s. Run `php artisan scryfall:gc-images --prune` to delete.',
                $this->fmtCount($imageOrphans['total']['orphans']),
                $this->formatService->formatBytes($imageOrphans['total']['bytes']),
            ));
        }

        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:update' finished in ".$this->formatService->formatMs($ms).", including $waitTime seconds idle time.");
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:update' finished in ".$this->formatService->formatMs($ms).", including $waitTime seconds idle time.");

        $this->buildAndEmitSummary($ms, $waitTime, $capturedFks, $restoredFks['restored'], $imageOrphans);
    }

    /**
     * Build the end-of-run summary by querying the now-live tables and
     * pulling cross-step counters from {@see ScryfallRunStats}.
     *
     * @param  array{art-crops: array{files: list<string>, orphans: int, bytes: int, superseded: list<string>, scanned: int}, card-images: array{files: list<string>, orphans: int, bytes: int, superseded: list<string>, scanned: int}, total: array{orphans: int, bytes: int, superseded: int, scanned: int}}  $imageOrphans
     */
    private function buildAndEmitSummary(int $totalMs, int $waitTime, int $capturedFkCount, int $restoredFkCount, array $imageOrphans): void
    {
        // Row counts across the swap tables (now live post-swap).
        foreach (ShadowTableRegistry::TABLES as $table) {
            $count = (int) DB::table($table)->count();
            $this->pushSummary('Live tables (rows after swap)', $table, $this->fmtCount($count));
        }

        // Oracle-tag classifications.
        $mld = (int) DB::table('oracle_cards')->where('mld', true)->count();
        $fetch = (int) DB::table('oracle_cards')->whereNotNull('fetch_pattern')->count();
        $landSearch = (int) DB::table('oracle_cards')->whereNotNull('land_search_pattern')->count();
        $this->pushSummary('Oracle tags', 'mass-land-denial (mld)', $this->fmtCount($mld).' cards');
        $this->pushSummary('Oracle tags', 'fetch_pattern', $this->fmtCount($fetch).' cards');
        $this->pushSummary('Oracle tags', 'land_search_pattern', $this->fmtCount($landSearch).' cards');
        $this->pushSummary(
            'Oracle tags',
            'kept from the previous run',
            ScryfallRunStats::$oracleTagColumnsCarriedOver === [] ? 'none' : implode(', ', array_keys(ScryfallRunStats::$oracleTagColumnsCarriedOver)),
        );

        // Bulk downloads (cross-step counter from ScryfallService).
        $this->pushSummary('Bulk downloads', 'reconnects (dropped or failed connection)', $this->fmtCount(ScryfallRunStats::$bulkReconnects));

        // Image download (cross-step counters from ImageDownloadService).
        $cardImagesProcessed = ScryfallRunStats::$cardImagesDownloaded + ScryfallRunStats::$cardImagesSkipped + ScryfallRunStats::$cardImagesFailed;
        $artCropsProcessed = ScryfallRunStats::$artCropsDownloaded + ScryfallRunStats::$artCropsSkipped + ScryfallRunStats::$artCropsFailed;
        $this->pushSummary('Images downloaded — card faces', 'processed', $this->fmtCount($cardImagesProcessed));
        $this->pushSummary('Images downloaded — card faces', 'downloaded', $this->fmtCount(ScryfallRunStats::$cardImagesDownloaded));
        $this->pushSummary('Images downloaded — card faces', 'skipped (already cached)', $this->fmtCount(ScryfallRunStats::$cardImagesSkipped));
        $this->pushSummary('Images downloaded — card faces', 'failed', $this->fmtCount(ScryfallRunStats::$cardImagesFailed));
        $this->pushSummary('Images downloaded — art crops', 'processed', $this->fmtCount($artCropsProcessed));
        $this->pushSummary('Images downloaded — art crops', 'downloaded', $this->fmtCount(ScryfallRunStats::$artCropsDownloaded));
        $this->pushSummary('Images downloaded — art crops', 'skipped (already cached)', $this->fmtCount(ScryfallRunStats::$artCropsSkipped));
        $this->pushSummary('Images downloaded — art crops', 'failed', $this->fmtCount(ScryfallRunStats::$artCropsFailed));
        $this->pushSummary('Superseded after the swap', 'old versions + leftovers deleted', $this->fmtCount(ScryfallRunStats::$supersededFilesDeleted));
        $this->pushSummary('Superseded after the swap', 'left in place (could not delete)', $this->fmtCount(ScryfallRunStats::$staleFilesUndeletable));
        $this->pushSummary('Set icons + symbols', 'SVG downloads failed', $this->fmtCount(ScryfallRunStats::$svgDownloadsFailed));

        // Image path resolution.
        $artLocal = (int) DB::table('default_cards')->whereNotNull('art_crop')->where('art_crop', 'not like', 'https://%')->count();
        $imgLocal = (int) DB::table('default_cards')->whereNotNull('card_image_0')->where('card_image_0', 'not like', 'https://%')->count();
        $this->pushSummary('Image paths', 'art crops resolved to local', $this->fmtCount($artLocal));
        $this->pushSummary('Image paths', 'card images resolved to local', $this->fmtCount($imgLocal));

        // Default-card-relations re-targeting (cross-step counter from DefaultCardsService).
        $this->pushSummary('Default card relations', 're-targeted (orphan → same-oracle printing)', $this->fmtCount(ScryfallRunStats::$relationsRetargeted));
        $this->pushSummary('Default card relations', 'dropped (no matching oracle)', $this->fmtCount(ScryfallRunStats::$relationsSkippedOrphan));
        $this->pushSummary('Default card relations', 'skipped (unknown component)', $this->fmtCount(ScryfallRunStats::$relationsSkippedComponent));

        // FK preservation around the swap.
        $this->pushSummary('FK preservation', 'captured + re-added', $this->fmtCount($capturedFkCount).' constraints');
        $this->pushSummary('FK preservation', 'restored from a crashed run', $this->fmtCount($restoredFkCount).' constraints');

        // Image orphan scan.
        $this->pushSummary('Image orphans (disk)', 'art-crops', $this->fmtCount($imageOrphans['art-crops']['orphans']).' files');
        $this->pushSummary('Image orphans (disk)', 'card-images', $this->fmtCount($imageOrphans['card-images']['orphans']).' files');
        $this->pushSummary('Image orphans (disk)', 'total reclaimable', $this->formatService->formatBytes($imageOrphans['total']['bytes']));

        // Runtime.
        $this->pushSummary('Runtime', 'total', $this->formatService->formatMs($totalMs));
        $this->pushSummary('Runtime', 'idle (sleep between steps)', $waitTime.' s');

        $this->emitSummary();
    }
}
