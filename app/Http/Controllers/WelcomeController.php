<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\Locale;
use App\Models\Artist;
use App\Models\BulkData;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Services\StatsService;
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
     *  - `siteStats` (collection totals + container counts) changes
     *    with user activity, but a few minutes of staleness on a marketing
     *    page is invisible — cached for 5 minutes.
     *  - `deckStats` mirrors the decks list page header, aggregated
     *    site-wide. Cached for 5 minutes alongside `siteStats`, and
     *    keyed by currency for the same reason (worth tiles are
     *    currency-specific).
     */
    public function show(Request $request): Response
    {
        $currency = $request->user()?->currency
            ?? Locale::tryFrom(app()->getLocale())?->defaultCurrency()
            ?? Currency::Eur;

        $scryfallStats = Cache::rememberForever(
            self::SCRYFALL_STATS_CACHE_KEY,
            fn () => self::buildScryfallStats()
        );

        // Per-currency cache key — totalPrice and mostValuableCard.price
        // are denominated in the visitor's currency, so EUR/USD
        // visitors must not see each other's numbers.
        $siteStats = Cache::remember(
            "welcome.siteStats.{$currency->value}",
            now()->addMinutes(5),
            fn () => StatsService::forSiteCollection($request->user())
        );

        $deckStats = Cache::remember(
            "welcome.deckStats.{$currency->value}",
            now()->addMinutes(5),
            fn () => StatsService::forSiteDecks($request->user())
        );

        return Inertia::render('Guest/Welcome', [
            'scryfallStats' => $scryfallStats,
            'siteStats' => $siteStats,
            'deckStats' => $deckStats,
        ]);
    }
}
