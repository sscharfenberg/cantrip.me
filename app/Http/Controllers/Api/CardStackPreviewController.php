<?php

namespace App\Http\Controllers\Api;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\ShowCardStackPreviewRequest;
use App\Models\CardStack;
use App\Services\CardPreviewService;
use App\Services\CardStackClaimService;
use App\Services\ContainerService;
use Illuminate\Http\JsonResponse;

class CardStackPreviewController extends Controller
{
    /**
     * Return card preview data for a single card stack.
     *
     * Card-level fields (name, images, set, artist, collector_number,
     * scryfall_uri, legalities, single-card price) come from
     * {@see CardPreviewService::payloadFor()} — shared with the
     * deck-card preview endpoint. The stack-specific extras (amount,
     * condition, finish, language, dates, total_price, claims) are
     * merged on top here.
     *
     * The unit price for this stack uses the finish-aware SQL CASE
     * (foil/etched/nonfoil) rather than the generic nonfoil price, so
     * the displayed price matches the stack's actual finish.
     *
     * Authorisation lives in {@see ShowCardStackPreviewRequest::authorize()}:
     * owner sees their own stacks unconditionally; everyone else only sees
     * stacks belonging to a container marked public.
     */
    public function show(ShowCardStackPreviewRequest $request, CardStack $cardStack): JsonResponse
    {
        $cardStack->load('defaultCard.set', 'defaultCard.artist', 'defaultCard.oracle.legalities', 'defaultCard.oracle.rulings');

        $card = $cardStack->defaultCard;
        $currency = $request->user()?->currency
            ?? Locale::from(app()->getLocale())->defaultCurrency();
        $unitPriceSql = ContainerService::unitPriceSql($currency);

        $priceRow = CardStack::query()
            ->where('card_stacks.id', $cardStack->id)
            ->join('default_cards', 'card_stacks.default_card_id', '=', 'default_cards.id')
            ->selectRaw("COALESCE({$unitPriceSql}, 0) as unit_price")
            ->selectRaw("COALESCE(card_stacks.amount * ({$unitPriceSql}), 0) as stack_price")
            ->first();

        $claims = CardStackClaimService::bulkClaimsForStacks([$cardStack->id])[$cardStack->id] ?? [];

        return response()->json([
            ...CardPreviewService::payloadFor($card, $currency),
            'price' => (float) ($priceRow->unit_price ?? 0),
            'amount' => $cardStack->amount,
            'condition' => $cardStack->condition?->value,
            'finish' => $cardStack->finish?->label(),
            'language' => $cardStack->language?->value ?? 'en',
            'created_at' => $cardStack->created_at?->toIso8601String(),
            'updated_at' => $cardStack->updated_at?->toIso8601String(),
            'total_price' => (float) ($priceRow->stack_price ?? 0),
            'proxy' => (bool) $cardStack->proxy,
            'claims' => $claims,
        ]);
    }
}
