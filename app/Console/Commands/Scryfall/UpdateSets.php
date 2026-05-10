<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\SetsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateSets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:sets {--target=live : Write target — `live` (default, truncates+inserts on the live table) or `shadow` (inserts into sets__shadow built by the orchestrator)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get all sets from scryfall and update database';

    private FormatService $formatService;

    private SetsService $setsService;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->setsService = new SetsService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $start = now();
        $this->info("artisan command 'scryfall:sets' started.");
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:sets' started.");
        Log::channel('scryfall')->info('=======================================================');
        $shadow = $this->option('target') === 'shadow';
        $this->setsService->updateSets(shadow: $shadow);
        $table = $shadow ? 'sets__shadow' : 'sets';
        $count = number_format(DB::table($table)->count(), 0, ',', '.');
        $this->line("inserted $count rows into $table.");
        Log::channel('scryfall')->notice("inserted $count rows into $table.");
        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:sets' finished in ".$this->formatService->formatMs($ms).'.');
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:sets' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
