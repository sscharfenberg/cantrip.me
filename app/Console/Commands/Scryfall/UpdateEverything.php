<?php

namespace App\Console\Commands\Scryfall;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\ScryfallUpdateFailedNotification;
use App\Services\FormatService;
use App\Services\Scryfall\ImageOrphanScanner;
use App\Services\Scryfall\ScryfallRunStats;
use App\Services\Scryfall\Shadow\ShadowTableRegistry;
use App\Services\Scryfall\Shadow\ShadowTableService;
use App\Services\Scryfall\Shadow\ShadowValidationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class UpdateEverything extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:update';

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
     */
    private function emitSummary(): void
    {
        if ($this->summary === []) {
            return;
        }
        $bar = str_repeat('═', 64);
        $this->newLine();
        $this->info($bar);
        $this->info(' scryfall:update summary');
        $this->info($bar);
        foreach ($this->summary as $section => $rows) {
            $this->newLine();
            $this->info($section);
            foreach ($rows as [$label, $value]) {
                $this->line(sprintf('  %-34s %s', $label, $value));
            }
        }
        $this->newLine();
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
     * ScryfallUpdateFailedNotification (mail + Discord), then re-throws.
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
            $this->dispatchFailureAlert($command, $e);

            throw $e;
        }
    }

    /**
     * Run an in-process orchestrator step (cleanup, createLike, FK
     * restoration, swap, dropRetired, validation) with the same alerting
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
            $this->dispatchFailureAlert("orchestrator:$name", $e);

            throw $e;
        }
    }

    /**
     * Send the failure alert to mail + Discord. Alert-side failures are
     * logged but never re-thrown — they must not mask the original error.
     */
    private function dispatchFailureAlert(string $command, Throwable $exception): void
    {
        try {
            $contact = (string) config('app.contact');
            Notification::route('mail', $contact)
                ->route(DiscordChannel::class, 'webhook')
                ->notify(new ScryfallUpdateFailedNotification($command, $exception));
        } catch (Throwable $alertException) {
            Log::channel('scryfall')->error('Failed to dispatch scryfall failure notification.', [
                'exception' => $alertException::class,
                'message' => $alertException->getMessage(),
            ]);
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
     *   6. Restore FK constraints on shadow tables (CREATE TABLE LIKE
     *      doesn't copy them — without this step the post-swap live
     *      tables would lose every cascade-on-delete and FK validation).
     *   7. Validate every FK relation against the shadow build.
     *      Includes user-data FKs (deck_cards, card_stacks) — Scryfall
     *      removing a card any user references aborts here.
     *   8. Atomic multi-table RENAME — old → __retired, shadows → live.
     *   9. Drop the now-stale __retired tables.
     *  10. Refresh welcome-page Scryfall stats cache from the new live data.
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

        // 1. Drop leftovers from any crashed prior run.
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
        // oracle_cards__shadow (mld, fetch_pattern columns). 1s pacing
        // is enforced inside the service.
        $this->runStep('scryfall:oracle-tags', shadow: true);
        $waitTime += $this->sleep();
        // translations: streams the ~2.5 GB all_cards bulk straight
        // from Scryfall via cerbero's Endpoint source (no on-disk
        // file). Depends on oracle_cards__shadow + oracle_card_faces__shadow
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

        // 7. Capture every FK that references one of the to-be-swapped
        //    tables (internal scryfall FKs AND user-data FKs from
        //    deck_cards / card_stacks / etc.). We must drop them before
        //    swap because MariaDB auto-rotates FK references when the
        //    parent table is renamed — without this, every FK would
        //    silently rotate to point at the __retired table that we're
        //    about to drop, leaving every user-data FK broken.
        $capturedFks = [];
        $this->runOrchestratorStep('captureForeignKeys', function () use (&$capturedFks): void {
            $capturedFks = $this->shadow->captureForeignKeys();
        });
        $this->info('shadow swap prep: captured '.count($capturedFks).' FK constraints referencing scryfall tables.');

        // 8. Drop those FK constraints.
        $this->runOrchestratorStep('dropForeignKeys', fn () => $this->shadow->dropForeignKeys($capturedFks));
        $this->info('shadow swap prep: dropped FK constraints (will be re-added after swap).');

        // 9. Atomic multi-table RENAME — sub-second swap.
        $this->runOrchestratorStep('swap', fn () => $this->shadow->swap());
        $this->info('shadow swap: atomic RENAME completed (live = previous shadow).');

        // 10. Drop the now-stale __retired tables (no FK constraints
        //     attached now — they were dropped in step 8).
        $this->runOrchestratorStep('dropRetired', fn () => $this->shadow->dropRetired());
        $this->info('shadow swap: dropped '.count(ShadowTableRegistry::TABLES).' __retired tables.');

        // 11. Re-add the captured FK constraints. References resolve to
        //     the new live tables now (which own the names the FKs
        //     target after the swap). Runs with FK_CHECKS=0 — existing
        //     data was already validated in step 6.
        $this->runOrchestratorStep('addForeignKeys', fn () => $this->shadow->addForeignKeys($capturedFks));
        $this->info('shadow swap: re-added '.count($capturedFks).' FK constraints to the new live tables.');

        // 12. Refresh the welcome-page Scryfall stats cache against the
        //     now-live data so the next visitor doesn't pay the
        //     recursive `find` + `du` cost.
        $this->runStep('scryfall:cache');

        // 13. Image-orphan dry scan against the now-live default_cards.
        //     Reports counts only — surface a hint with the prune command
        //     if any are found, but never delete from the auto flow.
        $imageOrphans = ['art-crops' => ['orphans' => 0, 'bytes' => 0], 'card-images' => ['orphans' => 0, 'bytes' => 0], 'total' => ['orphans' => 0, 'bytes' => 0]];
        $this->runOrchestratorStep('scanImageOrphans', function () use (&$imageOrphans): void {
            $imageOrphans = $this->imageOrphanScanner->scan();
        });
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

        $this->buildAndEmitSummary($ms, $waitTime, count($capturedFks), $imageOrphans);
    }

    /**
     * Build the end-of-run summary by querying the now-live tables and
     * pulling cross-step counters from {@see ScryfallRunStats}.
     *
     * @param  array{art-crops: array{orphans: int, bytes: int}, card-images: array{orphans: int, bytes: int}, total: array{orphans: int, bytes: int}}  $imageOrphans
     */
    private function buildAndEmitSummary(int $totalMs, int $waitTime, int $capturedFkCount, array $imageOrphans): void
    {
        // Row counts across the 10 swap tables (now live post-swap).
        foreach (ShadowTableRegistry::TABLES as $table) {
            $count = (int) DB::table($table)->count();
            $this->pushSummary('Live tables (rows after swap)', $table, $this->fmtCount($count));
        }

        // Oracle-tag classifications.
        $mld = (int) DB::table('oracle_cards')->where('mld', true)->count();
        $fetch = (int) DB::table('oracle_cards')->whereNotNull('fetch_pattern')->count();
        $this->pushSummary('Oracle tags', 'mass-land-denial (mld)', $this->fmtCount($mld).' cards');
        $this->pushSummary('Oracle tags', 'fetch_pattern', $this->fmtCount($fetch).' cards');

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
