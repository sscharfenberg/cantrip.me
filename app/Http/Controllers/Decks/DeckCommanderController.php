<?php

namespace App\Http\Controllers\Decks;

use App\Enums\DeckZone;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\ChangeDeckCommandZoneRequest;
use App\Http\Requests\Decks\ShowDeckCommanderPrintingsRequest;
use App\Http\Requests\Decks\UpdateDeckCommanderPrintingRequest;
use App\Models\Deck;
use App\Models\OracleCard;
use App\Services\DeckPrintingsService;
use App\Services\DeckService;
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
        $currentDefaultCardId = $deck->deckCards()
            ->where('zone', DeckZone::Command->value)
            ->where('oracle_card_id', $oracleCard->id)
            ->value('default_card_id');

        return response()->json(DeckPrintingsService::listForOracle(
            $request->user()->id,
            $oracleCard->id,
            $currentDefaultCardId,
        ));
    }

    /**
     * Swap the printing shown for a single commander.
     *
     * Updates the command-zone deck_card row's `default_card_id`. Color
     * identity, partner / signature-spell pairing, and bracket are all
     * derived from the oracle card and stay unchanged.
     */
    public function updatePrinting(UpdateDeckCommanderPrintingRequest $request, Deck $deck, OracleCard $oracleCard): JsonResponse
    {
        $newPrinting = $request->validated()['default_card_id'];
        $deckCard = $deck->deckCards()
            ->where('zone', DeckZone::Command->value)
            ->where('oracle_card_id', $oracleCard->id)
            ->first();
        $oldPrinting = $deckCard?->default_card_id;

        if ($deckCard !== null) {
            $deckCard->update(['default_card_id' => $newPrinting]);
        }

        if ($oldPrinting !== null) {
            $deck->remapHeroImage($oldPrinting, $newPrinting);
        }

        return response()->json(status: 204);
    }

    /**
     * Replace the deck's entire command zone with a new commander (and
     * optional partner-type companion or signature spell, depending on
     * format). Existing commanders are detached, the new ones attached,
     * and the deck's combined-color-identity `colors` field is recomputed.
     *
     * Per-card legality (color identity, format pool) is server-recomputed
     * on the next deck-page render — the user will see freshly illegal
     * cards highlighted via the existing legality panel without any
     * additional plumbing here.
     */
    public function change(ChangeDeckCommandZoneRequest $request, Deck $deck): JsonResponse
    {
        $validated = $request->validated();

        DeckService::setCommandZone(
            $deck,
            $validated['commander_id'],
            $validated['companion_id'] ?? null,
            $validated['signature_spell_id'] ?? null,
        );

        return response()->json(status: 204);
    }
}
