<?php

namespace App\Console\Commands\Scryfall;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\ScryfallUpdateFailedNotification;
use App\Services\FormatService;
use App\Services\Scryfall\Shadow\ShadowTableRegistry;
use App\Services\Scryfall\Shadow\ShadowTableService;
use App\Services\Scryfall\Shadow\ShadowValidationService;
use Illuminate\Console\Command;
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

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->shadow = new ShadowTableService;
        $this->validation = new ShadowValidationService;
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
        $this->runStep('scryfall:default_cards', shadow: true);
        $waitTime += $this->sleep();
        // rulings runs after oracle so its FK lookup against
        // oracle_cards__shadow finds every parent.
        $this->runStep('scryfall:rulings', shadow: true);
        $waitTime += $this->sleep();

        // 4. Image download — pure disk operation, no shadow concept.
        $this->runStep('scryfall:images');
        $waitTime += $this->sleep();

        // 5. Resolve image URLs → local paths on default_cards__shadow.
        $this->runStep('scryfall:resolve-paths', shadow: true);

        // 6. Restore FK constraints on every shadow table.
        $this->runOrchestratorStep('restoreForeignKeys', fn () => $this->shadow->restoreForeignKeys());
        $this->info('shadow build: restored '.count(ShadowTableRegistry::FK_RESTORATIONS).' FK constraints on shadow tables.');

        // 7. Validate FK integrity against the shadow build.
        $this->runOrchestratorStep('validateOrphans', fn () => $this->validateOrAbort());
        $this->info('shadow validation: passed (every FK relation resolves cleanly).');

        // 8. Atomic multi-table RENAME — sub-second swap.
        $this->runOrchestratorStep('swap', fn () => $this->shadow->swap());
        $this->info('shadow swap: atomic RENAME completed (live = previous shadow).');

        // 9. Drop the now-stale __retired tables.
        $this->runOrchestratorStep('dropRetired', fn () => $this->shadow->dropRetired());
        $this->info('shadow swap: dropped '.count(ShadowTableRegistry::TABLES).' __retired tables.');

        // 10. Refresh the welcome-page Scryfall stats cache against the
        //     now-live data so the next visitor doesn't pay the
        //     recursive `find` + `du` cost.
        $this->runStep('scryfall:cache');

        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:update' finished in ".$this->formatService->formatMs($ms).", including $waitTime seconds idle time.");
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:update' finished in ".$this->formatService->formatMs($ms).", including $waitTime seconds idle time.");
    }
}
