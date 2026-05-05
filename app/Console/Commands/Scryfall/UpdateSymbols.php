<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\SymbolsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateSymbols extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:symbols';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get all symbols from scryfall, save them to the public disk, and update the database';

    private FormatService $formatService;

    private SymbolsService $symbolsService;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->symbolsService = new SymbolsService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $start = now();
        $this->info("artisan command 'scryfall:symbols' started.");
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:symbols' started.");
        Log::channel('scryfall')->info('=======================================================');
        $this->symbolsService->updateSymbols();
        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:symbols' finished in ".$this->formatService->formatMs($ms).'.');
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:symbols' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
