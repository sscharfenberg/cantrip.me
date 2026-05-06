<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\ModifyDeckCardRequest;
use App\Http\Requests\Decks\MoveDeckCardZoneRequest;
use App\Http\Requests\Decks\ShowDeckCardPrintingsRequest;
use App\Http\Requests\Decks\SplitDeckCardRequest;
use App\Http\Requests\Decks\StoreDeckCardRequest;
use App\Http\Requests\Decks\UpdateDeckCardAssignedStacksRequest;
use App\Http\Requests\Decks\UpdateDeckCardCategoryRequest;
use App\Http\Requests\Decks\UpdateDeckCardPrintingRequest;
use App\Http\Requests\Decks\UpdateDeckCardQuantityRequest;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Services\DeckCardAssignmentService;
use App\Services\DeckCardService;
use App\Services\DeckPrintingsService;
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
            $deck->syncHeroImage();

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
        $deck->syncHeroImage();

        return response()->json(status: 204);
    }

    /**
     * Move one copy of this deck card to the opposite zone.
     *
     * Decrements the source row by one (deletes the row when the resulting
     * quantity is zero) and merges the moved copy into the target zone.
     *
     * "Matching" target rows share the same oracle card, printing, finish
     * and language, and carry no category. The query collects every such
     * row in the target zone, sums their quantities, deletes all but the
     * first, and writes the combined-plus-one quantity back to the
     * survivor. This both absorbs the moved copy AND consolidates any
     * pre-existing fragmented rows in the target zone (e.g. from add-card
     * flows that don't auto-merge) into a single row.
     *
     * The new/incremented row never carries a category (sideboard rows
     * can't have one, and main rows landing here via "move from
     * sideboard" weren't categorised either).
     *
     * Colors are zone-agnostic so no recalculation is needed.
     *
     * Returns the post-move identity of both rows so the frontend can
     * apply the change in place — synthesising the target row from the
     * source's already-rendered data when it didn't exist yet — instead
     * of having to reload the full `cards` prop.
     */
    public function moveZone(MoveDeckCardZoneRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $targetZone = $request->validated()['zone'];

        $result = DB::transaction(function () use ($deck, $deckCard, $targetZone): array {
            $matches = DeckCard::query()
                ->where('deck_id', $deck->id)
                ->where('oracle_card_id', $deckCard->oracle_card_id)
                ->where('default_card_id', $deckCard->default_card_id)
                ->where('zone', $targetZone)
                ->whereNull('category_id')
                ->orderBy('created_at')
                ->get();

            if ($matches->isNotEmpty()) {
                $target = $matches->shift();
                $extra = (int) $matches->sum('quantity');
                foreach ($matches as $duplicate) {
                    $duplicate->delete();
                }
                $target->update(['quantity' => $target->quantity + $extra + 1]);
            } else {
                $target = DeckCard::create([
                    'deck_id' => $deck->id,
                    'oracle_card_id' => $deckCard->oracle_card_id,
                    'default_card_id' => $deckCard->default_card_id,
                    'zone' => $targetZone,
                    'quantity' => 1,
                    'category_id' => null,
                ]);
            }

            $sourceQuantity = $deckCard->quantity - 1;
            if ($sourceQuantity <= 0) {
                $deckCard->delete();
            } else {
                $deckCard->update(['quantity' => $sourceQuantity]);
            }

            return [
                'source_quantity' => max(0, $sourceQuantity),
                'target_id' => $target->id,
                'target_quantity' => $target->quantity,
            ];
        });

        return response()->json($result);
    }

    /**
     * Split this deck card into multiple rows, one per chosen printing.
     *
     * The existing row is mutated to the first split entry (preserving its
     * id, category, and zone — typically used for the largest assignment),
     * and additional rows are inserted for the remaining entries with
     * matching `zone`, `category_id`, `finish`, and `language`.
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
                ]);
            }
        });

        $deck->syncHeroImage();

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
        $oldPrinting = $deckCard->default_card_id;
        $deckCard->update($request->validated());

        $deck->remapHeroImage($oldPrinting, $deckCard->default_card_id);

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
     * See {@see DeckPrintingsService::listForOracle} for the response shape
     * and the `in_collection` / `is_current` semantics.
     */
    public function printings(ShowDeckCardPrintingsRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        return response()->json(DeckPrintingsService::listForOracle(
            $request->user()->id,
            $deckCard->oracle_card_id,
            $deckCard->default_card_id,
        ));
    }

    /**
     * List the user's owned stacks of this deck card's printing, alongside
     * each stack's container and current claim status. Powers the per-card
     * "assign physical copy" picker (mode C).
     *
     * Each row carries:
     *  - `currently_assigned`: true when the stack is already pivoted to
     *    this exact deck_card (the picker pre-selects it).
     *  - `claim`: `{ deck_id, deck_name, is_this_deck_card }` when the
     *    stack is pivoted *somewhere*, null otherwise. The picker uses
     *    this to grey-out stacks claimed elsewhere.
     *
     * Owner-only — same authorisation shape as {@see printings}.
     */
    public function assignableStacks(ShowDeckCardPrintingsRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $stacks = CardStack::query()
            ->where('user_id', $deck->user_id)
            ->where('default_card_id', $deckCard->default_card_id)
            ->with(['container:id,name,type'])
            ->get();

        if ($stacks->isEmpty()) {
            return response()->json([]);
        }

        $stackIds = $stacks->pluck('id')->all();

        // One batched lookup for any current pivot rows on these stacks.
        // Joins to decks for the human-readable deck name shown when a
        // stack is claimed elsewhere.
        $claims = DB::table('deck_card_card_stack')
            ->join('deck_cards', 'deck_cards.id', '=', 'deck_card_card_stack.deck_card_id')
            ->join('decks', 'decks.id', '=', 'deck_cards.deck_id')
            ->whereIn('deck_card_card_stack.card_stack_id', $stackIds)
            ->get([
                'deck_card_card_stack.card_stack_id',
                'deck_card_card_stack.deck_card_id',
                'deck_cards.deck_id',
                'decks.name as deck_name',
            ])
            ->keyBy('card_stack_id');

        $payload = $stacks->map(function (CardStack $stack) use ($claims, $deckCard): array {
            $claim = $claims->get($stack->id);

            return [
                'id' => $stack->id,
                'amount' => (int) $stack->amount,
                'finish' => $stack->finish->value,
                'language' => $stack->language->value,
                'condition' => $stack->condition?->value,
                'container' => $stack->container ? [
                    'id' => $stack->container->id,
                    'name' => $stack->container->name,
                    'type' => $stack->container->type->value,
                ] : null,
                'currently_assigned' => $claim !== null && $claim->deck_card_id === $deckCard->id,
                'claim' => $claim === null ? null : [
                    'deck_id' => $claim->deck_id,
                    'deck_name' => $claim->deck_name,
                    'is_this_deck_card' => $claim->deck_card_id === $deckCard->id,
                ],
            ];
        })->values()->all();

        return response()->json($payload);
    }

    /**
     * Replace this deck card's claimed stack with the chosen one (or
     * clear the assignment when `card_stack_id` is null).
     *
     * Validates ownership / printing match / no foreign claim in the
     * FormRequest; the service does the atomic detach-and-attach with
     * an auto-split when the chosen stack is oversized.
     */
    public function updateAssignedStacks(UpdateDeckCardAssignedStacksRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $stackId = $request->validated()['card_stack_id'] ?? null;
        $stack = $stackId === null ? null : CardStack::query()->find($stackId);

        DeckCardAssignmentService::replaceAssignedStack($deckCard, $stack);

        return response()->json(status: 204);
    }
}
