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
use App\Services\CardStackClaimService;
use App\Services\DeckCardService;
use App\Services\DeckPrintingsService;
use App\Services\DeckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
     * duplicate on the second call. The companion it replaces frees its
     * claimed copies, asking first where they go while they lie in the
     * deck's deckbox ({@see askWhereFreedCopiesGo}); picking the current
     * companion again changes nothing, so it keeps its printing and claims.
     */
    public function store(SetDeckCompanionRequest $request, Deck $deck): JsonResponse
    {
        $oracleCardId = $request->validated()['oracle_card_id'];
        $current = $this->companionRow($deck);
        if ($current?->oracle_card_id === $oracleCardId) {
            return response()->json([
                'companion_oracle_card_id' => $current->oracle_card_id,
                'companion_default_card_id' => $current->default_card_id,
            ]);
        }

        $moveTo = $request->moveTarget();
        $question = $this->askWhereFreedCopiesGo($deck, $current, $moveTo);
        if ($question !== null) {
            return $question;
        }

        $companionRow = DB::transaction(function () use ($deck, $current, $oracleCardId, $moveTo): DeckCard {
            if ($current !== null) {
                CardStackClaimService::releaseClaimedCopies($current, 0, $moveTo);
                $current->delete();
            }

            return DeckCard::create([
                'deck_id' => $deck->id,
                'oracle_card_id' => $oracleCardId,
                'default_card_id' => DeckService::newestDefaultCard($oracleCardId)?->id,
                'zone' => DeckZone::Companion->value,
                'role' => DeckCardRole::Companion->value,
                'quantity' => 1,
            ]);
        });

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
     * (enforced in the FormRequest). Its claimed copies take the printing too
     * ({@see CardStackClaimService::switchPrinting}).
     */
    public function updatePrinting(SetDeckCompanionPrintingRequest $request, Deck $deck): JsonResponse
    {
        $companionRow = $deck->deckCards()
            ->where('role', DeckCardRole::Companion->value)
            ->first();

        if ($companionRow !== null) {
            CardStackClaimService::switchPrinting($companionRow, $request->validated()['default_card_id']);
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
            $request->user(),
            $companionRow?->oracle_card_id,
            $companionRow?->default_card_id,
        ));
    }

    /**
     * Clear the deck's companion.
     *
     * Recalculates `colors` after the clear so a removed companion that was
     * widening the deck's color set drops back out of the badge. Its claimed
     * copies are freed, asking first where they go while they lie in the
     * deck's deckbox ({@see askWhereFreedCopiesGo}).
     */
    public function destroy(RemoveDeckCompanionRequest $request, Deck $deck): JsonResponse
    {
        $current = $this->companionRow($deck);
        $moveTo = $request->moveTarget();
        $question = $this->askWhereFreedCopiesGo($deck, $current, $moveTo);
        if ($question !== null) {
            return $question;
        }

        if ($current !== null) {
            DB::transaction(function () use ($current, $moveTo): void {
                CardStackClaimService::releaseClaimedCopies($current, 0, $moveTo);
                $current->delete();
            });
        }

        DeckCardService::recalculateColors($deck);
        $deck->syncHeroImage();

        return response()->json(['companion_oracle_card_id' => null]);
    }

    /** The deck's companion row, with its card's name for the question below. */
    private function companionRow(Deck $deck): ?DeckCard
    {
        return $deck->deckCards()
            ->where('role', DeckCardRole::Companion->value)
            ->with('oracleCard:id,name')
            ->first();
    }

    /**
     * Before the companion leaves the deck: answer 409 with how many of its
     * claimed copies go, of which card and from which container while they
     * lie in the deck's deckbox and the request names no place (`move_to`),
     * so the page can ask, as "Remove" does
     * ({@see CardStackClaimService::freedCopiesNeedingAPlace}); null means
     * go ahead.
     */
    private function askWhereFreedCopiesGo(Deck $deck, ?DeckCard $companion, string|false|null $moveTo): ?JsonResponse
    {
        $freed = $companion === null
            ? null
            : CardStackClaimService::freedCopiesNeedingAPlace($deck, [[$companion, 0]], $moveTo);

        return $freed === null ? null : response()->json(['reason' => 'freed_copies_need_a_place', ...$freed], 409);
    }
}
