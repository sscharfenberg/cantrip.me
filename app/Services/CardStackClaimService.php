<?php

namespace App\Services;

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
}
