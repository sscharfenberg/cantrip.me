<?php

namespace App\Http\Controllers\Decks;

use App\Enums\DeckCardRole;
use App\Enums\DeckZone;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\RemoveDeckCompanionRequest;
use App\Http\Requests\Decks\SetDeckCompanionPrintingRequest;
use App\Http\Requests\Decks\SetDeckCompanionRequest;
use App\Http\Requests\Decks\ShowDeckCompanionPrintingsRequest;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Services\DeckCardService;
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
     *
     * For non-commander formats the companion contributes its own colored
     * mana symbols to the deck's `colors` field — recalculation is run
     * after the update so a freshly attached companion expands the badge
     * accordingly. Commander-family formats short-circuit inside the
     * service (their colors come from the command zone).
     *
     * Post-consolidation, the companion is a `deck_cards` row with
     * `zone=companion, role=companion`. Replacing it deletes any existing
     * companion row before inserting the new one — the
     * `UNIQUE(deck_id, role)` constraint would otherwise reject the
     * duplicate on the second call.
     */
    public function store(SetDeckCompanionRequest $request, Deck $deck): JsonResponse
    {
        $oracleCardId = $request->validated()['oracle_card_id'];
        $newest = DeckService::newestDefaultCard($oracleCardId);

        $deck->deckCards()
            ->where('role', DeckCardRole::Companion->value)
            ->delete();

        $companionRow = DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $oracleCardId,
            'default_card_id' => $newest?->id,
            'zone' => DeckZone::Companion->value,
            'role' => DeckCardRole::Companion->value,
            'quantity' => 1,
        ]);

        DeckCardService::recalculateColors($deck);
        $deck->syncHeroImage();

        return response()->json([
            'companion_oracle_card_id' => $companionRow->oracle_card_id,
            'companion_default_card_id' => $companionRow->default_card_id,
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
        $companionRow = $deck->deckCards()
            ->where('role', DeckCardRole::Companion->value)
            ->first();
        $oldPrinting = $companionRow?->default_card_id;
        $newPrinting = $request->validated()['default_card_id'];

        if ($companionRow !== null) {
            $companionRow->update(['default_card_id' => $newPrinting]);
        }

        if ($oldPrinting !== null) {
            $deck->remapHeroImage($oldPrinting, $newPrinting);
        }

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
        $companionRow = $deck->deckCards()
            ->where('role', DeckCardRole::Companion->value)
            ->first();

        return response()->json(DeckPrintingsService::listForOracle(
            $request->user()->id,
            $companionRow?->oracle_card_id,
            $companionRow?->default_card_id,
        ));
    }

    /**
     * Clear the deck's companion.
     *
     * Recalculates `colors` after the clear so a removed companion that was
     * widening the deck's color set drops back out of the badge.
     */
    public function destroy(RemoveDeckCompanionRequest $request, Deck $deck): JsonResponse
    {
        $deck->deckCards()
            ->where('role', DeckCardRole::Companion->value)
            ->delete();

        DeckCardService::recalculateColors($deck);
        $deck->syncHeroImage();

        return response()->json(['companion_oracle_card_id' => null]);
    }
}
