<?php

namespace App\Console\Commands\Scryfall;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\ScryfallUpdateFailedNotification;
use App\Services\FormatService;
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
    protected $description = 'Update every scryfall resource.';

    private FormatService $formatService;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
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
     * ScryfallUpdateFailedNotification (mail + Discord), then re-throws so
     * the outer try/finally can bring the site back up and the artisan run
     * exits non-zero (cron sees the failure).
     *
     * @throws Throwable
     */
    private function runStep(string $command): void
    {
        try {
            $exitCode = $this->call($command);
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
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        $start = now();
        $waitTime = 0;
        if (app()->isProduction()) {
            $this->call('down');
        } // 503 http requests
        try {
            $this->info("artisan command 'scryfall:update' started.");
            Log::channel('scryfall')->info('=======================================================');
            Log::channel('scryfall')->info("artisan command 'scryfall:update' started.");
            Log::channel('scryfall')->info('=======================================================');
            // update sets
            $this->runStep('scryfall:sets');
            $waitTime += $this->sleep();
            // update symbols.
            $this->runStep('scryfall:symbols');
            $waitTime += $this->sleep();
            // bulk data. we need this information for all of the other commands
            $this->runStep('scryfall:bulk');
            $waitTime += $this->sleep();
            // update oracle cards
            $this->runStep('scryfall:oracle');
            $waitTime += $this->sleep();
            // sync oracle-tagger flags onto oracle_cards (mass land denial,
            // …). Tags are not in bulk data — only reachable via the
            // search endpoint, so the command hits the API directly with
            // 1s-paced pagination.
            $this->runStep('scryfall:oracle-tags');
            $waitTime += $this->sleep();
            $this->runStep('scryfall:default_cards');
            $waitTime += $this->sleep();
            // import rulings (depends on oracle_cards being present;
            // does not need images, so runs before the image download)
            $this->runStep('scryfall:rulings');
            $waitTime += $this->sleep();
            // download missing art crop and card images to local disk
            $this->runStep('scryfall:images');
            $waitTime += $this->sleep();
            // resolve Scryfall URLs → local paths for downloaded images
            $this->runStep('scryfall:resolve-paths');
            // Pre-warm the welcome-page Scryfall stats cache with the freshly
            // imported dataset so the very first visitor doesn't pay the
            // recursive `find` + `du` cost.
            $this->runStep('scryfall:cache');
            $ms = $start->diffInMilliseconds(now());
            Log::channel('scryfall')->info('=======================================================');
            Log::channel('scryfall')->info("artisan command 'scryfall:update' finished in ".$this->formatService->formatMs($ms).", including $waitTime seconds idle time.");
            Log::channel('scryfall')->info('=======================================================');
            $this->info("artisan command 'scryfall:update' finished in ".$this->formatService->formatMs($ms).", including $waitTime seconds idle time.");
        } finally {
            if (app()->isProduction()) {
                $this->call('up');
            } // make sure the site goes back up.
        }
    }
}
