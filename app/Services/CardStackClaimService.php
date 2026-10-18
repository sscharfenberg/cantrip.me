<?php

namespace App\Services;

use App\Enums\DeckCardRole;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use Illuminate\Support\Facades\DB;

/**
 * Bulk lookup of "which decks claim this card stack" for the
 * collection-side claim badges (Phase 2.5).
 *
 * Lives in its own service because the data shape — keyed by
 * `card_stack_id` with a list of `{deck_id, deck_name}` claimers — is
 * orthogonal to the read-side mode logic in
 * {@see DeckCollectionStatusService}. Single batched join keeps the
 * collection / container datatables free of per-row N+1.
 *
 * The schema technically allows a single stack to be pivoted to
 * multiple deck_card rows (e.g. partial-coverage split into a 3+1
 * within the same deck). UX assumes one *deck* per stack, so this
 * service deduplicates per `(stack_id, deck_id)` pair — siblings
 * within the same deck collapse to one badge, not three.
 */
class CardStackClaimService
{
    /**
     * Map every stack id in `$stackIds` to its list of distinct deck
     * claimers. Stacks with no claims are absent from the result —
     * callers should default to an empty array per id.
     *
     * `is_commander` says whether the stack backs that deck's commander
     * (or partner) — the unclaim dialog warns before it would take one
     * out of the deck.
     *
     * @param  array<int, string>  $stackIds
     * @return array<string, array<int, array{deck_id: string, deck_name: string, is_commander: bool}>>
     */
    public static function bulkClaimsForStacks(array $stackIds): array
    {
        if ($stackIds === []) {
            return [];
        }

        $rows = DB::table('deck_card_card_stack')
            ->join('deck_cards', 'deck_cards.id', '=', 'deck_card_card_stack.deck_card_id')
            ->join('decks', 'decks.id', '=', 'deck_cards.deck_id')
            ->whereIn('deck_card_card_stack.card_stack_id', $stackIds)
            ->orderBy('decks.name')
            ->get([
                'deck_card_card_stack.card_stack_id',
                'decks.id as deck_id',
                'decks.name as deck_name',
                'deck_cards.role',
            ]);

        $result = [];
        foreach ($rows as $row) {
            $stackId = $row->card_stack_id;
            $result[$stackId] ??= [];

            $isCommander = in_array($row->role, [DeckCardRole::Commander->value, DeckCardRole::Partner->value], true);

            // Dedupe per-deck: if this stack is pivoted to multiple
            // deck_card rows in the same deck (split coverage), it
            // still surfaces as one badge, not several copies — a
            // commander among them is enough to mark the deck.
            $seen = null;
            foreach ($result[$stackId] as $index => $existing) {
                if ($existing['deck_id'] === $row->deck_id) {
                    $seen = $index;
                    break;
                }
            }
            if ($seen === null) {
                $result[$stackId][] = [
                    'deck_id' => $row->deck_id,
                    'deck_name' => $row->deck_name,
                    'is_commander' => $isCommander,
                ];
            } elseif ($isCommander) {
                $result[$stackId][$seen]['is_commander'] = true;
            }
        }

        return $result;
    }

    /**
     * Detach every deck claim against this stack in a single batched
     * delete and return the number of pivot rows removed.
     *
     * Phase 2.7's collection-side counterpart to the deck-side "Clear
     * assignment" picker. The schema permits a stack to be claimed by
     * several deck_card rows (rare partial-coverage split case); this
     * removes them all at once. Per-deck unclaim stays a future option
     * if anyone hits the multi-claim case in earnest.
     *
     * Sticky `decks.collection_mode = 'C'` is intentionally **not**
     * cleared here — the deck stays in mode C even if this was its only
     * claim. Clearing the pin is the deck-header modal's "Clear all
     * collection assignments" action (see {@see DeckCollectionModeService}).
     * Two distinct affordances for two distinct intents.
     *
     * No-op safe: returns 0 when the stack has no claims.
     */
    public static function unclaimAll(CardStack $stack): int
    {
        return DB::table('deck_card_card_stack')
            ->where('card_stack_id', $stack->id)
            ->delete();
    }

    /**
     * Take the copies `$stack` backs out of the decks claiming it — the
     * unclaim dialog's "remove from the deck list as well". Each claiming
     * deck card loses as many copies as the stack holds, at most all of
     * them; one left with none is deleted, its claim with it. Usually the
     * stack was split to match its deck card, so the card leaves the deck;
     * a deck card several stacks back keeps the other stacks' copies.
     *
     * Every affected deck's colours and hero image are brought up to date,
     * as deleting a card on the deck page does. Call before
     * {@see unclaimAll}, which then drops the claims still left.
     *
     * @return int The number of decks changed.
     */
    public static function removeClaimedCopiesFromDecks(CardStack $stack): int
    {
        $decks = [];
        foreach ($stack->deckCards()->with('deck')->get() as $deckCard) {
            $remaining = $deckCard->quantity - min($stack->amount, $deckCard->quantity);
            if ($remaining > 0) {
                $deckCard->update(['quantity' => $remaining]);
            } else {
                $deckCard->delete();
            }
            $decks[$deckCard->deck_id] = $deckCard->deck;
        }

        foreach ($decks as $deck) {
            DeckCardService::recalculateColors($deck);
            $deck->syncHeroImage();
        }

        return count($decks);
    }

    /**
     * The stacks claimed for each card of `$deck`, by deck card id — what
     * the deck page's "Unclaim" on a card frees. Cards nothing is claimed
     * for are absent.
     *
     * @return array<string, list<array{id: string, container_id: string|null}>>
     */
    public static function claimedStacksForDeck(Deck $deck): array
    {
        $result = [];
        $rows = DB::table('deck_card_card_stack')
            ->join('deck_cards', 'deck_cards.id', '=', 'deck_card_card_stack.deck_card_id')
            ->join('card_stacks', 'card_stacks.id', '=', 'deck_card_card_stack.card_stack_id')
            ->where('deck_cards.deck_id', $deck->id)
            ->orderBy('card_stacks.created_at')
            ->get(['deck_card_card_stack.deck_card_id', 'card_stacks.id', 'card_stacks.container_id']);
        foreach ($rows as $row) {
            $result[$row->deck_card_id][] = ['id' => $row->id, 'container_id' => $row->container_id];
        }

        return $result;
    }

    /**
     * Free the stacks claimed for one deck card — the deck page's "Unclaim"
     * on a card. Only this card's claims go; a stack also claimed by
     * another deck keeps that claim.
     *
     * In the same transaction the freed stacks can move (`$moveTo`: a
     * container id, null for unsorted, false to stay where they are), and
     * the card can leave the deck (`$removeFromDeck`), the deck's colours
     * and hero image refreshed as deleting a card on the deck page does.
     *
     * @return int The number of stacks freed.
     */
    public static function unclaimDeckCard(DeckCard $deckCard, string|false|null $moveTo, bool $removeFromDeck): int
    {
        return DB::transaction(function () use ($deckCard, $moveTo, $removeFromDeck): int {
            $stackIds = $deckCard->cardStacks()->pluck('card_stacks.id')->all();
            $deckCard->cardStacks()->detach();
            if ($moveTo !== false && $stackIds !== []) {
                CardStack::query()->whereIn('id', $stackIds)->update(['container_id' => $moveTo]);
            }
            if ($removeFromDeck) {
                $deck = $deckCard->deck;
                $deckCard->delete();
                DeckCardService::recalculateColors($deck);
                $deck->syncHeroImage();
            }

            return count($stackIds);
        });
    }
}
