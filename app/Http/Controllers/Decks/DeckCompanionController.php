<?php

namespace App\Http\Controllers\Decks;

use App\Enums\ContainerType;
use App\Enums\Finish;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\RemoveDeckCompanionRequest;
use App\Http\Requests\Decks\SetDeckCompanionPrintingRequest;
use App\Http\Requests\Decks\SetDeckCompanionRequest;
use App\Http\Requests\Decks\ShowDeckCompanionPrintingsRequest;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DefaultCard;
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
     * Mirrors {@see DeckCardController::printings} — same shape so the
     * switch-printing modal can be reused across deck cards and companions.
     */
    public function printings(ShowDeckCompanionPrintingsRequest $request, Deck $deck): JsonResponse
    {
        $printings = DefaultCard::query()
            ->with(['set:id,name,code,path', 'artist:id,name'])
            ->join('sets', 'default_cards.set_id', '=', 'sets.id')
            ->where('default_cards.oracle_id', $deck->companion_oracle_card_id)
            ->orderBy('sets.released_at', 'desc')
            ->orderBy('default_cards.id', 'desc')
            ->select('default_cards.*')
            ->get();

        $availableIds = CardStack::query()
            ->leftJoin('containers', 'card_stacks.container_id', '=', 'containers.id')
            ->where('card_stacks.user_id', $request->user()->id)
            ->whereIn('card_stacks.default_card_id', $printings->pluck('id')->all())
            ->where(function ($query): void {
                $query->whereNull('card_stacks.container_id')
                    ->orWhere('containers.type', '!=', ContainerType::Deckbox->value);
            })
            ->pluck('card_stacks.default_card_id')
            ->unique()
            ->flip();

        return response()->json(
            $printings
                ->map(fn (DefaultCard $card): array => [
                    'id' => $card->id,
                    'name' => $card->name,
                    'card_image_0' => $card->card_image_0,
                    'card_image_1' => $card->card_image_1,
                    'artist' => $card->artist?->name,
                    'cn' => $card->collector_number,
                    'finishes' => Finish::labelsFromMask($card->finishes),
                    'set' => $card->set ? [
                        'name' => $card->set->name,
                        'code' => $card->set->code,
                        'path' => $card->set->path,
                    ] : null,
                    'in_collection' => $availableIds->has($card->id),
                    'is_current' => $card->id === $deck->companion_default_card_id,
                ])
                ->values()
                ->all()
        );
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
