<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\Locale;
use App\Models\Artist;
use App\Models\BulkData;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Services\ContainerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    /**
     * Cache key for the Scryfall-derived stats block. Cached forever and
     * invalidated by {@see \App\Console\Commands\Scryfall\UpdateEverything}
     * once a fresh dataset has been imported.
     */
    public const SCRYFALL_STATS_CACHE_KEY = 'welcome.scryfallStats';

    /**
     * Get the number and total size of files under a storage subpath.
     * Used for both art crops and card images on the welcome page.
     *
     * @return array{num: int, size: int}
     */
    private static function imageDirectoryStats(string $relativePath): array
    {
        $path = storage_path("app/{$relativePath}");
        if (! is_dir($path)) {
            return ['num' => 0, 'size' => 0];
        }

        return [
            'num' => (int) trim(shell_exec("find -L $path -type f | wc -l")),
            'size' => (int) trim(shell_exec("du -sLb $path | cut -f1")),
        ];
    }

    /**
     * Build the Scryfall stats payload from scratch — full-table counts
     * plus the recursive `find` + `du` over the on-disk image dirs.
     *
     * Called both lazily by {@see show()} (via `Cache::rememberForever`)
     * and eagerly by {@see UpdateEverything::handle()} after a Scryfall
     * sync to pre-warm the cache, so visitors essentially never pay the
     * cold cost.
     */
    public static function buildScryfallStats(): array
    {
        return [
            'oracleCards' => [
                'num' => OracleCard::count(),
                'size' => BulkData::where('type', 'oracle_cards')->first()?->size,
            ],
            'defaultCards' => [
                'num' => DefaultCard::count(),
                'size' => BulkData::where('type', 'default_cards')->first()?->size,
            ],
            'sets' => Set::count(),
            'artists' => Artist::count(),
            'artCrops' => self::imageDirectoryStats('art-crops'),
            'cardImages' => self::imageDirectoryStats('card-images'),
        ];
    }

    /**
     * Display the welcome / landing page.
     *
     * Public entry point of the application, shown to unauthenticated visitors.
     *
     * Both stat blocks are cached because the page is purely informational —
     * staleness on a public landing page is invisible:
     *
     *  - `scryfallStats` includes recursive `find` + `du` over 100k+ image
     *    files plus a few full-table COUNTs. The numbers only change when
     *    `scryfall:update` runs (manually, ~weekly), so the cache is
     *    `rememberForever` — and {@see UpdateEverything::handle()} forgets
     *    the key at the end of each sync so the next visitor warms a fresh
     *    set. Net effect: visitors essentially never pay the cold cost.
     *  - `siteStats` (collection totals + container/deck counts) changes
     *    with user activity, but a few minutes of staleness on a marketing
     *    page is invisible — cached for 5 minutes.
     */
    public function show(Request $request): Response
    {
        $currency = Locale::tryFrom(app()->getLocale())?->defaultCurrency()
            ?? Currency::Eur;

        $scryfallStats = Cache::rememberForever(
            self::SCRYFALL_STATS_CACHE_KEY,
            fn () => self::buildScryfallStats()
        );

        // Per-currency cache key — totalPrice is denominated in the visitor's
        // currency, so EUR/USD visitors must not see each other's number.
        $siteStats = Cache::remember(
            "welcome.siteStats.{$currency->value}",
            now()->addMinutes(5),
            function () use ($currency): array {
                $unitPriceSql = ContainerService::unitPriceSql($currency);
                $totals = CardStack::query()
                    ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
                    ->selectRaw('COALESCE(SUM(card_stacks.amount), 0) as total_cards')
                    ->selectRaw("COALESCE(SUM(card_stacks.amount * ({$unitPriceSql})), 0) as total_price")
                    ->first();

                return [
                    'totalCards' => (int) $totals->total_cards,
                    'containers' => Container::count(),
                    'decks' => Deck::count(),
                    'totalPrice' => (float) $totals->total_price,
                ];
            }
        );

        return Inertia::render('Guest/Welcome', [
            'scryfallStats' => $scryfallStats,
            'siteStats' => $siteStats,
        ]);
    }
}
