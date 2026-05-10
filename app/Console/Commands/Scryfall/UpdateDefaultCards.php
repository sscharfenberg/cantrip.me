<?php

namespace App\Console\Commands\Scryfall;

use App\Services\FormatService;
use App\Services\Scryfall\DefaultCardsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateDefaultCards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scryfall:default_cards {--target=live : Write target — `live` (default) or `shadow` (writes to default_cards/default_card_relations/artists __shadow built by the orchestrator)}';

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
        $shadow = $this->option('target') === 'shadow';
        $this->defaultCardsService->updateAllCards(shadow: $shadow);
        $suffix = $shadow ? '__shadow' : '';
        foreach (['default_cards', 'default_card_relations', 'artists'] as $base) {
            $table = $base.$suffix;
            $count = number_format(DB::table($table)->count(), 0, ',', '.');
            $this->line("inserted $count rows into $table.");
            Log::channel('scryfall')->notice("inserted $count rows into $table.");
        }
        if ($this->defaultCardsService->relationsRetargeted > 0) {
            $retargeted = number_format($this->defaultCardsService->relationsRetargeted, 0, ',', '.');
            $this->line("re-targeted $retargeted orphan default_card_relations edges to default printings of the same oracle.");
        }
        if ($this->defaultCardsService->relationsSkippedOrphan > 0) {
            $skipped = number_format($this->defaultCardsService->relationsSkippedOrphan, 0, ',', '.');
            $this->line("dropped $skipped default_card_relations edges (no matching oracle in default_cards).");
        }
        if ($this->defaultCardsService->relationsSkippedComponent > 0) {
            $skipped = number_format($this->defaultCardsService->relationsSkippedComponent, 0, ',', '.');
            $this->line("skipped $skipped default_card_relations edges (unknown component).");
        }
        $ms = $start->diffInMilliseconds(now());
        Log::channel('scryfall')->info('=======================================================');
        Log::channel('scryfall')->info("artisan command 'scryfall:default_cards' finished in ".$this->formatService->formatMs($ms).'.');
        Log::channel('scryfall')->info('=======================================================');
        $this->info("artisan command 'scryfall:default_cards' finished in ".$this->formatService->formatMs($ms).'.');
    }
}
