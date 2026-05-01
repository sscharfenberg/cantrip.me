<?php

namespace App\Http\Controllers\Api;

use App\Enums\CardFormat;
use App\Enums\CardLegality;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\ShowCardStackPreviewRequest;
use App\Models\CardStack;
use App\Services\CardStackClaimService;
use App\Services\ContainerService;
use Illuminate\Http\JsonResponse;

class CardStackPreviewController extends Controller
{
    /**
     * Return card preview data for a single card stack.
     *
     * Loads the related default card (with set, artist, oracle) and resolves
     * the unit price based on the user's currency preference.
     *
     * Authorisation lives in {@see ShowCardStackPreviewRequest::authorize()}:
     * owner sees their own stacks unconditionally; everyone else only sees
     * stacks belonging to a container marked public.
     */
    public function show(ShowCardStackPreviewRequest $request, CardStack $cardStack): JsonResponse
    {
        $cardStack->load('defaultCard.set', 'defaultCard.artist', 'defaultCard.oracle.legalities');

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

        // Phase 2.5: deck claims for the badge in the preview body.
        $claims = CardStackClaimService::bulkClaimsForStacks([$cardStack->id])[$cardStack->id] ?? [];

        return response()->json([
            'name' => $card->name,
            'card_image_0' => $card->card_image_0,
            'card_image_1' => $card->card_image_1,
            'set_code' => $card->set?->code,
            'set_name' => $card->set?->name,
            'set_path' => $card->set?->path,
            'collector_number' => $card->collector_number,
            'artist' => $card->artist?->name,
            'amount' => $cardStack->amount,
            'condition' => $cardStack->condition?->value,
            'finish' => $cardStack->finish?->label(),
            'language' => $cardStack->language?->value ?? 'en',
            'created_at' => $cardStack->created_at?->toIso8601String(),
            'updated_at' => $cardStack->updated_at?->toIso8601String(),
            'price' => (float) ($priceRow->unit_price ?? 0),
            'total_price' => (float) ($priceRow->stack_price ?? 0),
            'scryfall_uri' => $card->oracle?->scryfall_uri,
            'claims' => $claims,
            'legalities' => collect(CardFormat::cases())->map(function (CardFormat $format) use ($card) {
                $match = $card->oracle?->legalities->first(fn ($l) => $l->format === $format->value);

                return [
                    'format' => $format->value,
                    'legality' => $match?->legality->value ?? CardLegality::NotLegal->value,
                ];
            })->all(),
        ]);
    }
}
