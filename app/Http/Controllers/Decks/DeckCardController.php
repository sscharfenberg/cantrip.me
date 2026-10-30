<?php

namespace App\Http\Controllers\Decks;

use App\Enums\CardCondition;
use App\Enums\CardLanguage;
use App\Enums\Finish;
use App\Formats\Capabilities\AddCopyFailure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decks\ModifyDeckCardRequest;
use App\Http\Requests\Decks\MoveDeckCardZoneRequest;
use App\Http\Requests\Decks\ShowDeckCardCopiesRequest;
use App\Http\Requests\Decks\ShowDeckCardPrintingsRequest;
use App\Http\Requests\Decks\SplitDeckCardRequest;
use App\Http\Requests\Decks\StoreDeckCardRequest;
use App\Http\Requests\Decks\UnclaimDeckCardRequest;
use App\Http\Requests\Decks\UpdateDeckCardAssignedStacksRequest;
use App\Http\Requests\Decks\UpdateDeckCardCategoryRequest;
use App\Http\Requests\Decks\UpdateDeckCardCopiesRequest;
use App\Http\Requests\Decks\UpdateDeckCardPrintingRequest;
use App\Http\Requests\Decks\UpdateDeckCardQuantityRequest;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\User;
use App\Services\CardStackClaimService;
use App\Services\DeckCardAssignmentService;
use App\Services\DeckCardCopiesService;
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
     *
     * Validated against the same format rules as a quantity increase — see
     * {@see refuseAddingCopies()}.
     */
    public function store(StoreDeckCardRequest $request, Deck $deck): JsonResponse
    {
        $validated = $request->validated();

        $defaultCard = DefaultCard::with('oracle')->findOrFail($validated['default_card_id']);

        $refusal = $this->refuseAddingCopies($deck, $defaultCard->oracle, 1);
        if ($refusal !== null) {
            return $refusal;
        }

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
     * Positive deltas are validated against format rules — see
     * {@see refuseAddingCopies()}. When the resulting quantity reaches zero or
     * below the card row is deleted entirely.
     *
     * Copies that leave free the claims they no longer need
     * ({@see CardStackClaimService::releaseClaimedCopies}), after asking
     * where freed copies in the deck's deckbox should go — see
     * {@see askWhereFreedCopiesGo()}.
     */
    public function updateQuantity(UpdateDeckCardQuantityRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $delta = $request->validated()['delta'];
        $newQuantity = $deckCard->quantity + $delta;
        $moveTo = $request->moveTarget();

        if ($delta < 0) {
            $question = $this->askWhereFreedCopiesGo($request->user(), $deck, $deckCard, $newQuantity, $moveTo);
            if ($question !== null) {
                return $question;
            }
        }

        if ($newQuantity <= 0) {
            DB::transaction(function () use ($deckCard, $moveTo): void {
                CardStackClaimService::releaseClaimedCopies($deckCard, 0, $moveTo);
                $deckCard->delete();
            });
            DeckCardService::recalculateColors($deck);
            $deck->syncHeroImage();

            return response()->json(['deleted' => true]);
        }

        if ($delta > 0) {
            $refusal = $this->refuseAddingCopies($deck, $deckCard->oracleCard, $delta);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        DB::transaction(function () use ($deckCard, $delta, $newQuantity, $moveTo): void {
            if ($delta < 0) {
                CardStackClaimService::releaseClaimedCopies($deckCard, $newQuantity, $moveTo);
            }
            $deckCard->update(['quantity' => $newQuantity]);
        });

        return response()->json(['quantity' => $newQuantity]);
    }

    /**
     * Before copies leave the deck: the claimed copies they free
     * ({@see CardStackClaimService::copiesToRelease}) may lie in the deck's
     * deckbox, and then have to go somewhere else. Until the request names
     * the place (`move_to`), answer 409 with how many copies go and from
     * which container, so the page can ask; null means go ahead. Freed
     * copies lying elsewhere stay where they are without asking.
     *
     * While the collection master switch is off, claims are invisible, so
     * nothing is asked — the claims are still freed, as deleting a card
     * always did, and the copies stay put.
     */
    private function askWhereFreedCopiesGo(User $user, Deck $deck, DeckCard $deckCard, int $quantity, string|false|null $moveTo): ?JsonResponse
    {
        if ($moveTo !== false || $deck->container_id === null || ! $user->collection_integration_enabled) {
            return null;
        }

        $release = CardStackClaimService::copiesToRelease($deckCard, $quantity);
        $fromDeckbox = array_filter($release, fn (array $entry): bool => $entry['stack']->container_id === $deck->container_id);
        if ($fromDeckbox === []) {
            return null;
        }

        return response()->json([
            'reason' => 'freed_copies_need_a_place',
            'count' => array_sum(array_column($release, 'amount')),
            'container_id' => $deck->container_id,
        ], 409);
    }

    /**
     * Ask the deck's format whether `$delta` more copies of `$oracleCard` may
     * be added. Shared by {@see store()} and {@see updateQuantity()} so adding
     * a card and pressing "+" enforce exactly the same rules.
     *
     * Copy limits sum every row of the oracle card in the deck (other
     * printings, other zones), so a singleton cannot slip past 1 by being
     * split across printings. The deck size is not checked: building past the
     * format's maximum and cutting down afterwards is allowed, and DeckValidator
     * reports the oversized deck instead.
     *
     * Returns the refusal as a 422 carrying the {@see AddCopyFailure} value
     * as `reason`, so the frontend can say why — or null when allowed.
     */
    private function refuseAddingCopies(Deck $deck, OracleCard $oracleCard, int $delta): ?JsonResponse
    {
        $copiesAfter = (int) $deck->deckCards()->where('oracle_card_id', $oracleCard->id)->sum('quantity') + $delta;

        $result = $deck->format->rules()->canAddCopy($oracleCard, $copiesAfter - 1);

        return $result->allowed ? null : response()->json(['reason' => $result->reason?->value], 422);
    }

    /**
     * Remove a deck card entirely, regardless of quantity. Its claims are
     * freed as for "−" on the last copy — see {@see updateQuantity()}.
     */
    public function destroy(ModifyDeckCardRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $moveTo = $request->moveTarget();
        $question = $this->askWhereFreedCopiesGo($request->user(), $deck, $deckCard, 0, $moveTo);
        if ($question !== null) {
            return $question;
        }

        DB::transaction(function () use ($deckCard, $moveTo): void {
            CardStackClaimService::releaseClaimedCopies($deckCard, 0, $moveTo);
            $deckCard->delete();
        });

        DeckCardService::recalculateColors($deck);
        $deck->syncHeroImage();

        return response()->json(status: 204);
    }

    /**
     * Free the collection stacks claimed for this card — the deck page's
     * "Unclaim" in the card's actions menu. The dialog can move the freed
     * stacks (`move_to`) and take the card out of the deck (`remove_from_deck`)
     * in the same step; see {@see CardStackClaimService::unclaimDeckCard}.
     * Answers whether the card left the deck, so the page knows what to reload.
     */
    public function unclaim(UnclaimDeckCardRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $removed = $request->boolean('remove_from_deck');
        CardStackClaimService::unclaimDeckCard($deckCard, $request->moveTarget(), $removed);

        return response()->json(['removed' => $removed]);
    }

    /**
     * Move one copy of this deck card to the opposite zone.
     *
     * Decrements the source row by one (deletes the row when the resulting
     * quantity is zero) and merges the moved copy into the target zone.
     *
     * "Matching" target rows share the same oracle card, printing and plan
     * (finish, language, proxy — {@see DeckCardCopiesService}), and carry
     * no category; a new target row takes the source's plan. The query
     * collects every such
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
     * of having to reload the full `cards` prop: the rows the merge
     * absorbed (`merged_ids`, so the page drops exactly those and not a
     * sibling with another plan) and both rows' `copies` groups, which
     * the move changes and a synthesised row can't take from its source.
     */
    public function moveZone(MoveDeckCardZoneRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $targetZone = $request->validated()['zone'];
        $withClaims = (bool) $request->user()->collection_integration_enabled;

        $result = DB::transaction(function () use ($deck, $deckCard, $targetZone, $withClaims): array {
            $matches = DeckCard::query()
                ->where('deck_id', $deck->id)
                ->where('oracle_card_id', $deckCard->oracle_card_id)
                ->where('default_card_id', $deckCard->default_card_id)
                ->where('zone', $targetZone)
                ->whereNull('category_id')
                ->where('finish', $deckCard->finish->value)
                ->where('language', $deckCard->language->value)
                ->where('proxy', $deckCard->proxy)
                ->orderBy('created_at')
                ->get();

            $mergedIds = [];
            if ($matches->isNotEmpty()) {
                $target = $matches->shift();
                $mergedIds = $matches->pluck('id')->all();
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
                    ...$deckCard->planAttributes(),
                ]);
            }

            $sourceQuantity = $deckCard->quantity - 1;
            if ($sourceQuantity <= 0) {
                $deckCard->delete();
            } else {
                $deckCard->update(['quantity' => $sourceQuantity]);
            }

            $copies = DeckCardCopiesService::groupsFor(collect([$deckCard, $target]), $withClaims);

            return [
                'source_quantity' => max(0, $sourceQuantity),
                'source_copies' => $sourceQuantity > 0 ? $copies[$deckCard->id] : [],
                'target_id' => $target->id,
                'target_quantity' => $target->quantity,
                'target_copies' => $copies[$target->id],
                'merged_ids' => $mergedIds,
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
     * matching `zone`, `category_id` and plan (`finish`, `language`,
     * `proxy`). A printing that lacks the planned finish gets its own first
     * finish instead ({@see DeckCard::booted}).
     *
     * Copy limits cannot be violated because the sum of split quantities is
     * required by the FormRequest to equal the existing quantity.
     */
    public function split(SplitDeckCardRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        /** @var array<int, array{default_card_id: string, quantity: int}> $splits */
        $splits = $request->validated()['splits'];

        DB::transaction(function () use ($deckCard, $splits): void {
            // Read before the first entry's update: saving onto a printing
            // without the planned finish re-fits it, and the other rows must
            // start from the plan the user had, not from that fallback.
            $plan = $deckCard->planAttributes();
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
                    ...$plan,
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
            $request->user(),
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

    /**
     * The copies of this card as "Edit copies" lists them: one group per
     * stack claimed for it, then the copies no stack claims, with their
     * plan. Each group names the finishes its printing exists in; a stack
     * group also says where the cards lie and which other decks claim the
     * stack, since editing it changes those decks too.
     *
     * While the collection master switch is off, claims stay as invisible
     * here as on the deck page: no stack group, every copy listed as
     * planned. A row that does have claims is then not `splittable` —
     * splitting it would move copies away from claims the user cannot see —
     * so it is re-planned whole ({@see UpdateDeckCardCopiesRequest}).
     *
     * Owner-only — same authorisation shape as {@see printings}.
     */
    public function copies(ShowDeckCardCopiesRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $integration = (bool) $request->user()->collection_integration_enabled;
        $unclaimed = DeckCardCopiesService::unclaimedCount($deckCard);
        $planned = $integration ? $unclaimed : $deckCard->quantity;
        $mask = (int) DefaultCard::query()->whereKey($deckCard->default_card_id)->value('finishes');

        $groups = [];
        if ($integration) {
            $stacks = $deckCard->cardStacks()
                ->with(['container:id,name', 'defaultCard:id,finishes'])
                ->orderBy('card_stacks.created_at')
                ->orderBy('card_stacks.id')
                ->get();
            $otherDecks = DB::table('deck_card_card_stack')
                ->join('deck_cards', 'deck_cards.id', '=', 'deck_card_card_stack.deck_card_id')
                ->join('decks', 'decks.id', '=', 'deck_cards.deck_id')
                ->whereIn('deck_card_card_stack.card_stack_id', $stacks->pluck('id'))
                ->where('decks.id', '!=', $deck->id)
                ->orderBy('decks.name')
                ->get(['deck_card_card_stack.card_stack_id', 'decks.id', 'decks.name'])
                ->groupBy('card_stack_id');
            foreach ($stacks as $stack) {
                $groups[] = [
                    'stack_id' => $stack->id,
                    'count' => (int) $stack->amount,
                    'splittable' => true,
                    'finish' => $stack->finish->label(),
                    'language' => $stack->language->value,
                    'proxy' => (bool) $stack->proxy,
                    'condition' => $stack->condition?->value,
                    'finishes' => Finish::labelsFromMask((int) $stack->defaultCard?->finishes),
                    'container' => $stack->container ? ['id' => $stack->container->id, 'name' => $stack->container->name] : null,
                    'other_decks' => ($otherDecks->get($stack->id) ?? collect())->unique('id')->pluck('name')->values()->all(),
                ];
            }
        }
        if ($planned > 0) {
            $groups[] = [
                'stack_id' => null,
                'count' => $planned,
                'splittable' => $integration || $unclaimed === $deckCard->quantity,
                'finish' => $deckCard->finish->label(),
                'language' => $deckCard->language->value,
                'proxy' => $deckCard->proxy,
                'condition' => null,
                'finishes' => Finish::labelsFromMask($mask),
                'container' => null,
                'other_decks' => [],
            ];
        }

        return response()->json([
            'integration' => $integration,
            'languages' => array_column(CardLanguage::cases(), 'value'),
            'conditions' => array_column(CardCondition::cases(), 'value'),
            'claimed' => $deckCard->quantity - $planned,
            'groups' => $groups,
        ]);
    }

    /**
     * Edit `count` copies of one group: with a `stack_id`, of that claimed
     * stack — the collection's cards ({@see DeckCardCopiesService::editClaimedStack});
     * without one, of the unclaimed copies' plan — all of them, or fewer,
     * which split off into a row of their own
     * ({@see DeckCardCopiesService::replan}).
     */
    public function updateCopies(UpdateDeckCardCopiesRequest $request, Deck $deck, DeckCard $deckCard): JsonResponse
    {
        $validated = $request->validated();
        $finish = Finish::fromLabel($validated['finish']);
        $language = CardLanguage::from($validated['language']);
        $proxy = (bool) $validated['proxy'];

        if (($validated['stack_id'] ?? null) !== null) {
            $stack = CardStack::query()->findOrFail($validated['stack_id']);
            DeckCardCopiesService::editClaimedStack(
                $stack,
                (int) $validated['count'],
                $finish,
                $language,
                $proxy,
                array_key_exists('condition', $validated)
                    ? CardCondition::tryFrom((string) $validated['condition'])
                    : $stack->condition,
            );
        } else {
            DeckCardCopiesService::replan($deckCard, (int) $validated['count'], $finish, $language, $proxy);
        }

        return response()->json(status: 204);
    }
}
