<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\DefaultCardsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateDefaultCards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:default_cards';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update database with the "default" cards from scryfall.';

    private FormatService $formatService;

    private DefaultCardsService $defaultCardsService;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->defaultCardsService = new DefaultCardsService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $start = now();
        $this->info("artisan command 'scryfall:default_cards' started.");
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:default_cards' started.");
        Log::channel('scryfall')->info('=======================================================');
        $this->defaultCardsService->updateAllCards();
        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:default_cards' finished in ".$this->formatService->formatMs($ms).'.');
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:default_cards' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
