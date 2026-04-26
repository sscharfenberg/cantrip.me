<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
     * Execute the console command.
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
            $this->call('scryfall:sets');
            $waitTime += $this->sleep();
            // update symbols.
            $this->call('scryfall:symbols');
            $waitTime += $this->sleep();
            // bulk data. we need this information for all of the other commands
            $this->call('scryfall:bulk');
            $waitTime += $this->sleep();
            // update oracle cards
            $this->call('scryfall:oracle');
            $waitTime += $this->sleep();
            $this->call('scryfall:default_cards');
            $waitTime += $this->sleep();
            // download missing art crop and card images to local disk
            $this->call('scryfall:images');
            $waitTime += $this->sleep();
            // resolve Scryfall URLs → local paths for downloaded images
            $this->call('scryfall:resolve-paths');
            // Pre-warm the welcome-page Scryfall stats cache with the freshly
            // imported dataset so the very first visitor doesn't pay the
            // recursive `find` + `du` cost.
            $this->call('scryfall:cache');
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
