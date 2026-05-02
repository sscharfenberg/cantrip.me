<?php

namespace App\Services;

use App\Models\CardStack;
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
     * @param  array<int, string>  $stackIds
     * @return array<string, array<int, array{deck_id: string, deck_name: string}>>
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
            ]);

        $result = [];
        foreach ($rows as $row) {
            $stackId = $row->card_stack_id;
            $result[$stackId] ??= [];

            // Dedupe per-deck: if this stack is pivoted to multiple
            // deck_card rows in the same deck (split coverage), it
            // still surfaces as one badge, not several copies.
            $alreadySeen = false;
            foreach ($result[$stackId] as $existing) {
                if ($existing['deck_id'] === $row->deck_id) {
                    $alreadySeen = true;
                    break;
                }
            }
            if (! $alreadySeen) {
                $result[$stackId][] = [
                    'deck_id' => $row->deck_id,
                    'deck_name' => $row->deck_name,
                ];
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
}
