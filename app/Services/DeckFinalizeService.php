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
     * - Empty assignments + empty buy_new map → behaves like {@see transitionToBuilt}.
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
     *   whose `quantity > N` AND doesn't tick "bought new", the
     *   deck_card is split into a claimed row of `quantity = N`
     *   (carrying the pivot rows) and a leftover row of `quantity =
     *   remainder` (no pivot).
     * - Buy-new pad: rows where `buy_new[deck_card_id] === true` get
     *   any uncovered slots covered by a freshly created (or merged
     *   into an existing matching unsorted stack) card_stack via
     *   {@see CardStackService::addToCollection}. The bought stack is
     *   attached to the deck_card so the row renders
     *   `claimed_for_this_deck` after redirect, and the deck_card is
     *   not split (the row is fully covered).
     *
     * @param  array<string, array<int, string>>  $assignments  Keyed by deck_card_id → list of card_stack_ids.
     * @param  array<string, bool>  $buyNew  Keyed by deck_card_id → "bought new" flag.
     */
    public static function persistAssignments(Deck $deck, array $assignments, array $buyNew, ?string $containerId): void
    {
        DB::transaction(function () use ($deck, $assignments, $buyNew, $containerId): void {
            $anyPivotsWritten = false;

            // Walk every deck_card touched by either side of the
            // payload — assignments, buy_new, or both. Skipping a row
            // means neither flag was set for it, which is the
            // implicit "leave this row alone" path.
            $touchedDeckCardIds = array_unique(array_merge(
                array_keys($assignments),
                array_keys(array_filter($buyNew)),
            ));

            foreach ($touchedDeckCardIds as $deckCardId) {
                $stackIds = array_values(array_unique(array_filter($assignments[$deckCardId] ?? [])));
                $buyForThisRow = (bool) ($buyNew[$deckCardId] ?? false);
                if ($stackIds === [] && ! $buyForThisRow) {
                    continue;
                }

                $deckCard = DeckCard::query()
                    ->where('id', $deckCardId)
                    ->where('deck_id', $deck->id)
                    ->first();
                if ($deckCard === null) {
                    continue;
                }

                if (self::claimStacksForDeckCard($deckCard, $stackIds, $buyForThisRow, $containerId)) {
                    $anyPivotsWritten = true;
                }
            }

            // Pin the deck to mode C the first time at least one pivot is
            // written. Sticky: stays 'C' even if the user later deletes
            // every claimed stack from the collection (cascade removes the
            // pivots but the deck still tracks claims). Per design, C→B
            // is an explicit "clear all assignments" action — see 2.6.
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
     * quantity (partial coverage), splitting any oversized stack down
     * to size first, and optionally padding any uncovered slots with a
     * freshly-bought stack when the user ticked "bought new".
     *
     * Returns true when at least one pivot row was written, so the
     * caller can pin the deck to mode C only when the user actually
     * claimed something.
     *
     * Defensive against `quantity <= 0` — a no-op short-circuit so a
     * malformed deck_card row can't trigger spurious work.
     *
     * @param  array<int, string>  $stackIds
     */
    private static function claimStacksForDeckCard(DeckCard $deckCard, array $stackIds, bool $buyNew, ?string $containerId): bool
    {
        $needed = (int) $deckCard->quantity;
        if ($needed <= 0) {
            return false;
        }

        $stacks = CardStack::query()
            ->whereIn('id', $stackIds)
            ->where('user_id', $deckCard->deck->user_id)
            ->where('default_card_id', $deckCard->default_card_id)
            ->get();

        $alreadyClaimed = self::claimedStackIds($stacks->pluck('id')->all());
        $stacks = $stacks->reject(fn (CardStack $s): bool => in_array($s->id, $alreadyClaimed, true));

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

        // Buy-new pad: if the user ticked "bought new" and the existing
        // assignment leaves the row uncovered, mint (or merge into) a
        // matching unsorted-or-deckbox stack via
        // {@see CardStackService::addToCollection} so the deck_card
        // row ends fully covered with no leftover split.
        //
        // Defaults: language en / finish nonfoil / condition null —
        // baseline values to keep the wizard quick. Users who track
        // foil/etched copies go through the collection page.
        if ($buyNew && $claimed < $needed) {
            $uncovered = $needed - $claimed;
            $bought = CardStackService::addToCollection($deckCard->deck->user, [
                'default_card_id' => $deckCard->default_card_id,
                'amount' => $uncovered,
                'language' => 'en',
                'condition' => null,
                'finish' => 'nonfoil',
                'container_id' => $containerId,
            ]);
            $stacksToAttach[] = $bought['stack']->id;
            $claimed += $uncovered;
        }

        // Dedupe — `addToCollection` may have merged the bought
        // amount into a stack the user already assigned (same
        // printing + matching language/finish/condition/container),
        // in which case the same stack id appears twice and the
        // pivot's composite PK rejects the second insert.
        $stacksToAttach = array_values(array_unique($stacksToAttach));

        if ($stacksToAttach === []) {
            return false;
        }

        $targetDeckCard = $deckCard;
        if ($claimed < $needed) {
            // Partial coverage (and no buy-new pad): split the deck_card
            // so the leftover slot is its own row (and renders with
            // "not_owned" status).
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
