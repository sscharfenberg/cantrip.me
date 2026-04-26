<?php

namespace App\Console\Commands\Scryfall;

use App\Http\Controllers\WelcomeController;
use App\Services\FormatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Refresh the welcome-page Scryfall stats cache.
 *
 * The welcome page renders a stats block ("oracle cards: 36,983 etc.") that
 * involves full-table COUNTs and recursive `find` + `du` over the on-disk
 * art-crop and card-image directories — a multi-second job on cold cache.
 * The result is cached forever via {@see WelcomeController::SCRYFALL_STATS_CACHE_KEY}
 * because the underlying numbers only change when the Scryfall dataset is
 * refreshed.
 *
 * This command rebuilds and atomically replaces that cache entry so the very
 * first visitor after a Scryfall sync (or after manual cache wipes) doesn't
 * pay the cold cost. {@see UpdateEverything} runs it as the last step of
 * `scryfall:update`; you can also invoke it directly any time you want to
 * force a refresh.
 */
class WarmStatsCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-warm the welcome-page Scryfall stats cache (counts + image directory sizes).';

    private FormatService $formatService;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $start = now();
        $this->info("artisan command 'scryfall:cache' started.");
        Log::channel('scryfall')->info("artisan command 'scryfall:cache' started.");

        Cache::forever(
            WelcomeController::SCRYFALL_STATS_CACHE_KEY,
            WelcomeController::buildScryfallStats()
        );

        $ms = $start->diffInMilliseconds(now());
        $duration = $this->formatService->formatMs($ms);
        $this->info("artisan command 'scryfall:cache' finished in {$duration}.");
        Log::channel('scryfall')->info("artisan command 'scryfall:cache' finished in {$duration}.");
    }
}
