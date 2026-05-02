<?php

namespace App\Http\Controllers\Api;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ShowDefaultCardPreviewRequest;
use App\Models\DefaultCard;
use App\Services\CardPreviewService;
use Illuminate\Http\JsonResponse;

class DefaultCardPreviewController extends Controller
{
    /**
     * Return card preview data for a single default card (printing).
     *
     * Used by the deck-side preview modal: same JSON shape as the stack
     * preview endpoint, minus the stack-specific fields. When the
     * caller passes `?quantity=N`, the response also includes
     * `amount: N` and `total_price: price * N` — used to display the
     * deck's copy count and implied total alongside the single-card
     * price. Unauthenticated visitors can hit this because the
     * underlying Scryfall card data is public.
     */
    public function show(ShowDefaultCardPreviewRequest $request, DefaultCard $defaultCard): JsonResponse
    {
        $defaultCard->load('set', 'artist', 'oracle.legalities');

        $currency = $request->user()?->currency
            ?? Locale::from(app()->getLocale())->defaultCurrency();

        $payload = CardPreviewService::payloadFor($defaultCard, $currency);

        $quantity = $request->integer('quantity');
        if ($quantity > 0) {
            $payload['amount'] = $quantity;
            $payload['total_price'] = (float) $payload['price'] * $quantity;
        }

        return response()->json($payload);
    }
}
