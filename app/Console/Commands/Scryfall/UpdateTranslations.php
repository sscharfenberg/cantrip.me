<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\TranslationsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Imports foreign-language card-name translations from Scryfall's
 * `all_cards` bulk export. Drives the deck/collection/commander
 * search services for non-English input — see
 * {@see TranslationsService} for the full
 * design.
 *
 * Standalone (`scryfall:translations`) → writes to live tables and
 * truncates them first. Orchestrated by `scryfall:update`
 * (`--target=shadow`) → writes to `__shadow` tables built by the
 * orchestrator and skips truncate.
 *
 * The bulk is ~2.5 GB and streams directly from Scryfall via
 * cerbero's `Endpoint` source — no on-disk file is created. The
 * sibling bulk imports (`scryfall:oracle`, `scryfall:default_cards`,
 * `scryfall:rulings`) use the same streaming pattern.
 */
class UpdateTranslations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:translations {--target=live : Write target — `live` (default) or `shadow` (writes to oracle_card_translations__shadow + oracle_card_face_translations__shadow built by the orchestrator; FK lookups read oracle_cards__shadow + oracle_card_faces__shadow)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import foreign-language card-name translations from Scryfall\'s all_cards bulk.';

    private FormatService $formatService;

    private TranslationsService $translationsService;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->translationsService = new TranslationsService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $start = now();
        $this->info("artisan command 'scryfall:translations' started.");
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:translations' started.");
        Log::channel('scryfall')->info('=======================================================');

        $shadow = $this->option('target') === 'shadow';
        $this->translationsService->updateTranslations(shadow: $shadow);

        // Console-only row-count echo. The same numbers are logged from
        // TranslationsService::flushOracleBuffer / flushFaceBuffer — those
        // are the authoritative scryfall-channel entries and fire whether
        // the command is invoked directly or by UpdateEverything.
        $oracleTable = $shadow ? 'oracle_card_translations__shadow' : 'oracle_card_translations';
        $faceTable = $shadow ? 'oracle_card_face_translations__shadow' : 'oracle_card_face_translations';
        $oracleCount = number_format(DB::table($oracleTable)->count(), 0, ',', '.');
        $faceCount = number_format(DB::table($faceTable)->count(), 0, ',', '.');
        $this->line("inserted $oracleCount rows into $oracleTable.");
        $this->line("inserted $faceCount rows into $faceTable.");

        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:translations' finished in ".$this->formatService->formatMs($ms).'.');
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:translations' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
