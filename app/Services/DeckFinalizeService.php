<?php

namespace App\Services;

use App\Enums\DeckState;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use Illuminate\Support\Facades\DB;

/**
 * Owns the planned→built transition logic, including the optional pivot
 * writes and auto-split bookkeeping that the finalize wizard performs.
 *
 * Two entry points:
 *  - {@see persistAssignments} runs the full wizard submit (claim stacks,
 *    auto-split partial coverage, set deckbox, transition state).
 *  - {@see transitionToBuilt} runs the bare state change for mode A or
 *    "skip" submissions.
 */
class DeckFinalizeService
{
    /**
     * Persist the wizard's assignments and transition the deck to Built.
     *
     * - Empty assignments map → behaves like {@see transitionToBuilt}.
     * - Each `[deck_card_id => [stack_id, ...]]` entry attaches the
     *   listed stacks via the pivot.
     * - Auto-split #1: when a stack's `amount` exceeds the assigned
     *   slice, the stack is split first via
     *   {@see CardStackService::splitStack} so only the claimed copies
     *   carry the pivot row. Splitting decisions assume one stack
     *   covers exactly its `amount` worth of slots — partial
     *   coverage of one stack across multiple deck_cards is not
     *   surfaced by the wizard UI.
     * - Auto-split #2: when the user assigns N stacks for a deck_card
     *   whose `quantity > N`, the deck_card is split into a claimed
     *   row of `quantity = N` (carrying the pivot rows) and a leftover
     *   row of `quantity = remainder` (no pivot).
     *
     * @param  array<string, array<int, string>>  $assignments  Keyed by deck_card_id → list of card_stack_ids.
     */
    public static function persistAssignments(Deck $deck, array $assignments, ?string $containerId): void
    {
        DB::transaction(function () use ($deck, $assignments, $containerId): void {
            $anyPivotsWritten = false;

            foreach ($assignments as $deckCardId => $stackIds) {
                $stackIds = array_values(array_unique(array_filter($stackIds)));
                if ($stackIds === []) {
                    continue;
                }

                $deckCard = DeckCard::query()
                    ->where('id', $deckCardId)
                    ->where('deck_id', $deck->id)
                    ->first();
                if ($deckCard === null) {
                    continue;
                }

                if (self::claimStacksForDeckCard($deckCard, $stackIds)) {
                    $anyPivotsWritten = true;
                }
            }

            // Pin the deck to mode C the first time at least one pivot is
            // written. Sticky: stays 'C' even if the user later deletes
            // every claimed stack from the collection (cascade removes the
            // pivots but the deck still tracks claims). Per design, C→B
            // is an explicit "clear all assignments" action — not yet built.
            $updates = ['state' => DeckState::Built->value];
            if ($containerId !== null) {
                $updates['container_id'] = $containerId;
            }
            if ($anyPivotsWritten && $deck->collection_mode !== 'C') {
                $updates['collection_mode'] = 'C';
            }
            $deck->update($updates);
        });
    }

    /**
     * Transition the deck to Built without touching the pivot. Used by
     * the wizard's "Skip" path and by mode A's direct transition.
     */
    public static function transitionToBuilt(Deck $deck): void
    {
        $deck->update(['state' => DeckState::Built->value]);
    }

    /**
     * Claim a set of stacks for one deck_card row, splitting the
     * deck_card if the user assigned fewer stacks than the deck_card's
     * quantity (partial coverage), and splitting any oversized stack
     * down to size first.
     *
     * Returns true when at least one pivot row was written, so the
     * caller can pin the deck to mode C only when the user actually
     * claimed something.
     *
     * @param  array<int, string>  $stackIds
     */
    private static function claimStacksForDeckCard(DeckCard $deckCard, array $stackIds): bool
    {
        $stacks = CardStack::query()
            ->whereIn('id', $stackIds)
            ->where('user_id', $deckCard->deck->user_id)
            ->where('default_card_id', $deckCard->default_card_id)
            ->get();

        $alreadyClaimed = self::claimedStackIds($stacks->pluck('id')->all());
        $stacks = $stacks->reject(fn (CardStack $s): bool => in_array($s->id, $alreadyClaimed, true));

        if ($stacks->isEmpty()) {
            return false;
        }

        $needed = $deckCard->quantity;
        $claimed = 0;
        $stacksToAttach = [];

        foreach ($stacks as $stack) {
            if ($claimed >= $needed) {
                break;
            }

            $remaining = $needed - $claimed;
            if ($stack->amount > $remaining) {
                // Stack is bigger than what's left to claim — split off
                // exactly `remaining` copies and attach the new stack.
                $split = CardStackService::splitStack($stack, $remaining);
                $stacksToAttach[] = $split->id;
                $claimed += $remaining;
            } else {
                $stacksToAttach[] = $stack->id;
                $claimed += (int) $stack->amount;
            }
        }

        if ($stacksToAttach === []) {
            return false;
        }

        $targetDeckCard = $deckCard;
        if ($claimed < $needed) {
            // Partial coverage: split the deck_card so the leftover slot
            // is its own row (and renders with "not_owned" status).
            $leftover = $needed - $claimed;
            $deckCard->update(['quantity' => $claimed]);
            DeckCard::create([
                'deck_id' => $deckCard->deck_id,
                'oracle_card_id' => $deckCard->oracle_card_id,
                'default_card_id' => $deckCard->default_card_id,
                'category_id' => $deckCard->category_id,
                'zone' => $deckCard->zone->value,
                'quantity' => $leftover,
                'finish' => $deckCard->finish->value,
                'language' => $deckCard->language->value,
            ]);
        }

        $targetDeckCard->cardStacks()->attach($stacksToAttach);

        return true;
    }

    /**
     * Find which of the given stack ids already have a pivot row to
     * any deck. Used to silently drop double-claim attempts (e.g. when
     * the user submits a stack a second deck has already grabbed
     * out-of-band).
     *
     * @param  array<int, string>  $stackIds
     * @return array<int, string>
     */
    private static function claimedStackIds(array $stackIds): array
    {
        if ($stackIds === []) {
            return [];
        }

        return DB::table('deck_card_card_stack')
            ->whereIn('card_stack_id', $stackIds)
            ->pluck('card_stack_id')
            ->all();
    }
}
