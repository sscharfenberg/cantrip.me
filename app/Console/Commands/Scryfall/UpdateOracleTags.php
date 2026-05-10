<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\OracleTagsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateOracleTags extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:oracle-tags {--target=live : Write target — `live` (default) or `shadow` (UPDATEs oracle_cards__shadow built by the orchestrator)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Scryfall oracle-tagger flags onto oracle_cards. Covers boolean tags (mass-land-denial → mld) and the fetch-pattern parse (fetchland → fetch_pattern). Tags are not in bulk data — only reachable via the search endpoint, so this command paginates the API with a ≥1s pacing held across every registered sync.';

    private FormatService $formatService;

    private OracleTagsService $oracleTagsService;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->oracleTagsService = new OracleTagsService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $start = now();
        $this->info("artisan command 'scryfall:oracle-tags' started.");
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:oracle-tags' started.");
        Log::channel('scryfall')->info('=======================================================');
        $shadow = $this->option('target') === 'shadow';
        $this->oracleTagsService->syncOracleTags(shadow: $shadow);
        $table = $shadow ? 'oracle_cards__shadow' : 'oracle_cards';
        $mld = number_format(DB::table($table)->where('mld', true)->count(), 0, ',', '.');
        $fetch = number_format(DB::table($table)->whereNotNull('fetch_pattern')->count(), 0, ',', '.');
        $this->line("$mld cards classified mass-land-denial in $table.mld.");
        $this->line("$fetch cards classified as fetchlands in $table.fetch_pattern.");
        Log::channel('scryfall')->notice("$mld cards classified mass-land-denial, $fetch cards classified as fetchlands in $table.");
        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:oracle-tags' finished in ".$this->formatService->formatMs($ms).'.');
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:oracle-tags' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
