<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\ResolveImagePathsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResolveImagePaths extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:resolve-paths {--target=live : Write target — `live` (default) or `shadow` (UPDATEs default_cards__shadow built by the orchestrator)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resolve Scryfall image URLs to local paths for art crops and card images on default_cards.';

    private FormatService $formatService;

    private ResolveImagePathsService $resolveService;

    public function __construct()
    {
        parent::__construct();
        $this->formatService = new FormatService;
        $this->resolveService = new ResolveImagePathsService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $start = now();
        $this->info("artisan command 'scryfall:resolve-paths' started.");
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:resolve-paths' started.");
        Log::channel('scryfall')->info('=======================================================');

        $shadow = $this->option('target') === 'shadow';
        $this->resolveService->useTarget($shadow);

        $artCrops = $this->resolveService->resolveArtCropPaths();
        $this->line("resolved $artCrops art crop paths to local cache.");
        Log::channel('scryfall')->notice("resolved $artCrops art crop paths to local cache.");

        $cardImages = $this->resolveService->resolveCardImagePaths();
        $this->line("resolved $cardImages card image paths to local cache.");
        Log::channel('scryfall')->notice("resolved $cardImages card image paths to local cache.");

        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:resolve-paths' finished in ".$this->formatService->formatMs($ms).'.');
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:resolve-paths' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
