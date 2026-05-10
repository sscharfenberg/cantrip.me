<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\RulingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateRulings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:rulings {--target=live : Write target — `live` (default) or `shadow` (writes to rulings__shadow built by the orchestrator; FK lookup reads oracle_cards__shadow)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update database with rulings from scryfall.';

    private FormatService $formatService;

    private RulingsService $rulingsService;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->rulingsService = new RulingsService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $start = now();
        $this->info("artisan command 'scryfall:rulings' started.");
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:rulings' started.");
        Log::channel('scryfall')->info('=======================================================');
        $shadow = $this->option('target') === 'shadow';
        $this->rulingsService->updateRulings(shadow: $shadow);
        $table = $shadow ? 'rulings__shadow' : 'rulings';
        $count = number_format(DB::table($table)->count(), 0, ',', '.');
        $this->line("inserted $count rows into $table.");
        Log::channel('scryfall')->notice("inserted $count rows into $table.");
        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:rulings' finished in ".$this->formatService->formatMs($ms).'.');
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:rulings' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
