<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\OracleTagsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateOracleTags extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:oracle-tags';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Scryfall oracle-tagger flags (mass land denial, …) onto oracle_cards. Tags are not in bulk data — only reachable via the search endpoint, so this command paginates the API with a 1s pacing.';

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
        $this->oracleTagsService->syncOracleTags();
        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:oracle-tags' finished in ".$this->formatService->formatMs($ms).'.');
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:oracle-tags' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
