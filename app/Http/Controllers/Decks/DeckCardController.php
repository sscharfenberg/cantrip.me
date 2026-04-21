<?php

namespace App\Http\Controllers\Decks;

use App\Enums\ContainerType;
use App\Enums\Finish;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\ModifyDeckCardRequest;
use App\Http\Requests\Decks\ShowDeckCardPrintingsRequest;
use App\Http\Requests\Decks\SplitDeckCardRequest;
use App\Http\Requests\Decks\StoreDeckCardRequest;
use App\Http\Requests\Decks\UpdateDeckCardCategoryRequest;
use App\Http\Requests\Decks\UpdateDeckCardPrintingRequest;
use App\Http\Requests\Decks\UpdateDeckCardQuantityRequest;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Services\DeckCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class DeckCardController extends Controller
{
    /**
     * Add a card to the deck.
     *
     * Expects `default_card_id` (specific printing) and `zone` (main/side).
     * The oracle card is resolved from the default card automatically.
     */
    public function store(StoreDeckCardRequest $request, Deck $deck): JsonResponse
    {
        $validated = $request->validated();

        $defaultCard = DefaultCard::findOrFail($validated['default_card_id']);

        $deckCard = DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $defaultCard->oracle_id,
            'default_card_id' => $defaultCard->id,
            'zone' => $validated['zone'],
        ]);

        DeckCardService::recalculateColors($deck);

        return response()->json(['id' => $deckCard->id], 201);
    }

    /**
     * Change a deck card's quantity by a signed delta.
     *
     * Positive deltas are validated against format rules (singleton, max copies,
     * deck size). When the resulting quantity reaches zero or below the card row
     * is deleted entirely.
     */
    public function updateQuantity(UpdateDeckCardQuantityRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $delta = $request->validated()['delta'];
        $newQuantity = $deckCard->quantity + $delta;

        if ($newQuantity <= 0) {
            $deckCard->delete();
            DeckCardService::recalculateColors($deck);

            return response()->json(['deleted' => true]);
        }

        if ($delta > 0) {
            $oracleCard = $deckCard->oracleCard;
            $currentDeckSize = $deck->deckCards()->sum('quantity');
            // Sum sibling rows (other printings of the same oracle card in this deck)
            // so copy limits account for split rows. Without this a singleton could
            // be incremented past 1 on any single row when split across printings.
            $siblingSum = $deck->deckCards()
                ->where('oracle_card_id', $deckCard->oracle_card_id)
                ->where('id', '!=', $deckCard->id)
                ->sum('quantity');

            $result = $deck->format->rules()->canAddCopy(
                $oracleCard,
                $siblingSum + $newQuantity - 1,
                $currentDeckSize + $delta - 1,
            );

            abort_unless($result->allowed, 422);
        }

        $deckCard->update(['quantity' => $newQuantity]);

        return response()->json(['quantity' => $newQuantity]);
    }

    /**
     * Remove a deck card entirely, regardless of quantity.
     */
    public function destroy(ModifyDeckCardRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $deckCard->delete();

        DeckCardService::recalculateColors($deck);

        return response()->json(status: 204);
    }

    /**
     * Split this deck card into multiple rows, one per chosen printing.
     *
     * The existing row is mutated to the first split entry (preserving its
     * id, category, zone, and card_stack_id — typically used for the largest
     * assignment), and additional rows are inserted for the remaining entries
     * with matching `zone`, `category_id`, `finish`, and `language`.
     *
     * Copy limits cannot be violated because the sum of split quantities is
     * required by the FormRequest to equal the existing quantity.
     */
    public function split(SplitDeckCardRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        /** @var array<int, array{default_card_id: string, quantity: int}> $splits */
        $splits = $request->validated()['splits'];

        DB::transaction(function () use ($deckCard, $splits): void {
            $first = array_shift($splits);
            $deckCard->update([
                'default_card_id' => $first['default_card_id'],
                'quantity' => $first['quantity'],
            ]);

            foreach ($splits as $entry) {
                DeckCard::create([
                    'deck_id' => $deckCard->deck_id,
                    'oracle_card_id' => $deckCard->oracle_card_id,
                    'default_card_id' => $entry['default_card_id'],
                    'category_id' => $deckCard->category_id,
                    'zone' => $deckCard->zone->value,
                    'quantity' => $entry['quantity'],
                    'finish' => $deckCard->finish->value,
                    'language' => $deckCard->language->value,
                ]);
            }
        });

        return response()->json(status: 204);
    }

    /**
     * Swap the printing shown for a deck card.
     *
     * The chosen `default_card_id` must belong to the same oracle card as
     * the deck card (enforced in the FormRequest). Color identity and
     * legality are therefore unchanged, so no deck-level recalculation
     * is needed.
     */
    public function updatePrinting(UpdateDeckCardPrintingRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $deckCard->update($request->validated());

        return response()->json(status: 204);
    }

    /**
     * Update a deck card's category assignment.
     *
     * Accepts a nullable `category_id` — null removes the card from any
     * custom category, returning it to the default type-based grouping.
     */
    public function updateCategory(UpdateDeckCardCategoryRequest $request, Deck $deck, DeckCard $deckCard): RedirectResponse
    {
        $deckCard->update($request->validated());

        return redirect()->back();
    }

    /**
     * List all printings of this deck card's oracle card.
     *
     * Each row carries the card image URLs, set + artist + collector number,
     * and a `in_collection` flag. A printing counts as "in collection" when
     * the user owns a card stack for that default card that sits in a
     * non-deckbox container (a null container — unsorted collection — counts).
     */
    public function printings(ShowDeckCardPrintingsRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $printings = DefaultCard::query()
            ->with(['set:id,name,code,path', 'artist:id,name'])
            ->join('sets', 'default_cards.set_id', '=', 'sets.id')
            ->where('default_cards.oracle_id', $deckCard->oracle_card_id)
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
                    'is_current' => $card->id === $deckCard->default_card_id,
                ])
                ->values()
                ->all()
        );
    }
}
