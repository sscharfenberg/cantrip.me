<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\RemoveDeckCompanionRequest;
use App\Http\Requests\Decks\SetDeckCompanionPrintingRequest;
use App\Http\Requests\Decks\SetDeckCompanionRequest;
use App\Http\Requests\Decks\ShowDeckCompanionPrintingsRequest;
use App\Models\Deck;
use App\Services\DeckPrintingsService;
use App\Services\DeckService;
use Illuminate\Http\JsonResponse;

class DeckCompanionController extends Controller
{
    /**
     * Set (or replace) the deck's companion.
     *
     * Auto-selects the newest printing so the frontend can display a
     * specific card image without a round-trip printing picker.
     */
    public function store(SetDeckCompanionRequest $request, Deck $deck): JsonResponse
    {
        $oracleCardId = $request->validated()['oracle_card_id'];
        $newest = DeckService::newestDefaultCard($oracleCardId);

        $deck->update([
            'companion_oracle_card_id' => $oracleCardId,
            'companion_default_card_id' => $newest?->id,
        ]);

        return response()->json([
            'companion_oracle_card_id' => $deck->companion_oracle_card_id,
            'companion_default_card_id' => $deck->companion_default_card_id,
        ]);
    }

    /**
     * Swap the printing shown for the deck's companion.
     *
     * The chosen `default_card_id` must belong to the companion's oracle card
     * (enforced in the FormRequest).
     */
    public function updatePrinting(SetDeckCompanionPrintingRequest $request, Deck $deck): JsonResponse
    {
        $deck->update(['companion_default_card_id' => $request->validated()['default_card_id']]);

        return response()->json(status: 204);
    }

    /**
     * List all printings of the companion's oracle card.
     *
     * Same shape as {@see DeckCardController::printings} so the
     * switch-printing modal can be reused across deck cards, companions
     * and commanders.
     */
    public function printings(ShowDeckCompanionPrintingsRequest $request, Deck $deck): JsonResponse
    {
        return response()->json(DeckPrintingsService::listForOracle(
            $request->user()->id,
            $deck->companion_oracle_card_id,
            $deck->companion_default_card_id,
        ));
    }

    /**
     * Clear the deck's companion (oracle + printing).
     */
    public function destroy(RemoveDeckCompanionRequest $request, Deck $deck): JsonResponse
    {
        $deck->update([
            'companion_oracle_card_id' => null,
            'companion_default_card_id' => null,
        ]);

        return response()->json(['companion_oracle_card_id' => null]);
    }
}
