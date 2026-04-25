<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\ShowDeckCommanderPrintingsRequest;
use App\Http\Requests\Decks\UpdateDeckCommanderPrintingRequest;
use App\Models\Deck;
use App\Models\OracleCard;
use App\Services\DeckPrintingsService;
use Illuminate\Http\JsonResponse;

/**
 * Per-commander actions on a deck's command zone.
 *
 * The deck-creation flow handles which card(s) sit in the command zone;
 * this controller just exposes the per-commander actions a user can take
 * from the deck detail page (currently: switch printing, with room to grow
 * for "change commander" later).
 */
class DeckCommanderController extends Controller
{
    /**
     * List all printings of a single commander's oracle card.
     *
     * Same response shape as the deck-card and companion printings
     * endpoints — see {@see DeckPrintingsService::listForOracle}.
     */
    public function printings(ShowDeckCommanderPrintingsRequest $request, Deck $deck, OracleCard $oracleCard): JsonResponse
    {
        $currentDefaultCardId = $deck->commanders()
            ->where('oracle_card_id', $oracleCard->id)
            ->first()
            ?->pivot
            ?->default_card_id;

        return response()->json(DeckPrintingsService::listForOracle(
            $request->user()->id,
            $oracleCard->id,
            $currentDefaultCardId,
        ));
    }

    /**
     * Swap the printing shown for a single commander.
     *
     * Only updates the `commanders` pivot row's `default_card_id`. Color
     * identity, partner / signature-spell pairing, and bracket are all
     * derived from the oracle card and stay unchanged.
     */
    public function updatePrinting(UpdateDeckCommanderPrintingRequest $request, Deck $deck, OracleCard $oracleCard): JsonResponse
    {
        $deck->commanders()->updateExistingPivot($oracleCard->id, [
            'default_card_id' => $request->validated()['default_card_id'],
        ]);

        return response()->json(status: 204);
    }
}
