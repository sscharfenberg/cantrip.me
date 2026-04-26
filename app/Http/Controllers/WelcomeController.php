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
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    /**
     * Get the number and total size of cached art crop images.
     *
     * @return array{num: int, size: int}
     */
    private function getArtCropStats(): array
    {
        $path = storage_path('app/art-crops');
        if (! is_dir($path)) {
            return ['num' => 0, 'size' => 0];
        }

        return [
            'num' => (int) trim(shell_exec("find -L $path -type f | wc -l")),
            'size' => (int) trim(shell_exec("du -sLb $path | cut -f1")),
        ];
    }

    /**
     * Get the number and total size of card images.
     *
     * @return array{num: int, size: int}
     */
    private function getCardImageStats(): array
    {
        $path = storage_path('app/card-images');
        if (! is_dir($path)) {
            return ['num' => 0, 'size' => 0];
        }

        return [
            'num' => (int) trim(shell_exec("find -L $path -type f | wc -l")),
            'size' => (int) trim(shell_exec("du -sLb $path | cut -f1")),
        ];
    }

    /**
     * Display the welcome / landing page.
     *
     * Public entry point of the application, shown to unauthenticated visitors.
     */
    public function show(Request $request): Response
    {
        $currency = Locale::tryFrom(app()->getLocale())?->defaultCurrency()
            ?? Currency::Eur;
        $unitPriceSql = ContainerService::unitPriceSql($currency);

        $siteStats = CardStack::query()
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->selectRaw('COALESCE(SUM(card_stacks.amount), 0) as total_cards')
            ->selectRaw("COALESCE(SUM(card_stacks.amount * ({$unitPriceSql})), 0) as total_price")
            ->first();

        return Inertia::render('Guest/Welcome', [
            'scryfallStats' => [
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
                'artCrops' => $this->getArtCropStats(),
                'cardImages' => $this->getCardImageStats(),
            ],
            'siteStats' => [
                'totalCards' => (int) $siteStats->total_cards,
                'containers' => Container::count(),
                'decks' => Deck::count(),
                'totalPrice' => (float) $siteStats->total_price,
            ],
        ]);
    }
}
