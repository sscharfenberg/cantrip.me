<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\OracleCardsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateOracleCards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:oracle {--target=live : Write target — `live` (default) or `shadow` (writes to oracle_cards/oracle_card_faces/legalities __shadow built by the orchestrator)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update database with oracle cards from scryfall.';

    private FormatService $formatService;

    private OracleCardsService $oracleCardsService;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->oracleCardsService = new OracleCardsService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $start = now();
        $this->info("artisan command 'scryfall:oracle' started.");
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:oracle' started.");
        Log::channel('scryfall')->info('=======================================================');
        $shadow = $this->option('target') === 'shadow';
        $this->oracleCardsService->updateOracleCards(shadow: $shadow);
        $suffix = $shadow ? '__shadow' : '';
        foreach (['oracle_cards', 'oracle_card_faces', 'legalities'] as $base) {
            $table = $base.$suffix;
            $count = number_format(DB::table($table)->count(), 0, ',', '.');
            $this->line("inserted $count rows into $table.");
            Log::channel('scryfall')->notice("inserted $count rows into $table.");
        }
        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:oracle' finished in ".$this->formatService->formatMs($ms).'.');
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:oracle' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
