<?php

namespace App\Http\Controllers\Decks;

use App\Enums\DeckZone;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\ChangeDeckCommandZoneRequest;
use App\Http\Requests\Decks\ShowDeckCommanderPrintingsRequest;
use App\Http\Requests\Decks\UpdateDeckCommanderPrintingRequest;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\OracleCard;
use App\Services\CardStackClaimService;
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
            $request->user(),
            $oracleCard->id,
            $currentDefaultCardId,
        ));
    }

    /**
     * Swap the printing shown for a single commander.
     *
     * Updates the command-zone deck_card row's `default_card_id`, its
     * claimed copies with it ({@see CardStackClaimService::switchPrinting}).
     * Color identity, partner / signature-spell pairing, and bracket are all
     * derived from the oracle card and stay unchanged.
     */
    public function updatePrinting(UpdateDeckCommanderPrintingRequest $request, Deck $deck, OracleCard $oracleCard): JsonResponse
    {
        $deckCard = $deck->deckCards()
            ->where('zone', DeckZone::Command->value)
            ->where('oracle_card_id', $oracleCard->id)
            ->first();

        if ($deckCard !== null) {
            CardStackClaimService::switchPrinting($deckCard, $request->validated()['default_card_id']);
        }

        return response()->json(status: 204);
    }

    /**
     * Replace the deck's entire command zone with a new commander (and
     * optional partner-type companion or signature spell, depending on
     * format). A card that stays keeps its row, the new ones are added,
     * and the deck's combined-color-identity `colors` field is recomputed
     * ({@see DeckService::setCommandZone}). A card that leaves frees its
     * claimed copies; while they lie in the deck's deckbox, the answer is
     * 409 (`freed_copies_need_a_place`, count, container, the cards' name)
     * until the request names a `move_to`, as "Remove" asks.
     *
     * Per-card legality (color identity, format pool) is server-recomputed
     * on the next deck-page render — the user will see freshly illegal
     * cards highlighted via the existing legality panel without any
     * additional plumbing here.
     */
    public function change(ChangeDeckCommandZoneRequest $request, Deck $deck): JsonResponse
    {
        $validated = $request->validated();
        $commandZone = [
            $validated['commander_id'],
            $validated['companion_id'] ?? null,
            $validated['signature_spell_id'] ?? null,
        ];
        $moveTo = $request->moveTarget();

        $leaving = DeckService::commandZoneLeaving($deck, ...$commandZone)
            ->map(fn (DeckCard $row): array => [$row, 0])
            ->all();
        $freed = CardStackClaimService::freedCopiesNeedingAPlace($deck, $leaving, $moveTo);
        if ($freed !== null) {
            return response()->json(['reason' => 'freed_copies_need_a_place', ...$freed], 409);
        }

        DeckService::setCommandZone($deck, ...$commandZone, moveTo: $moveTo);

        return response()->json(status: 204);
    }
}
