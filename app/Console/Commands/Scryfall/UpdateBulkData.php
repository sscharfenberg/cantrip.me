<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\BulkdataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateBulkData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:bulk {--target=live : Write target — `live` (default) or `shadow` (writes to bulk_data__shadow built by the orchestrator)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get bulk data from scryfall';

    private FormatService $formatService;

    private BulkdataService $bulkdataService;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->bulkdataService = new BulkdataService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $start = now();
        $this->info("artisan command 'scryfall:bulk' started.");
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:bulk' started.");
        Log::channel('scryfall')->info('=======================================================');
        $shadow = $this->option('target') === 'shadow';
        $this->bulkdataService->getBulkMetadata(shadow: $shadow);
        $table = $shadow ? 'bulk_data__shadow' : 'bulk_data';
        $count = number_format(DB::table($table)->count(), 0, ',', '.');
        $this->line("inserted $count rows into $table.");
        Log::channel('scryfall')->notice("inserted $count rows into $table.");
        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:bulk' finished in ".$this->formatService->formatMs($ms).'.');
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:bulk' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
