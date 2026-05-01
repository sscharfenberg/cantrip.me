<?php

namespace App\Services;

use App\Models\Deck;
use Illuminate\Support\Facades\DB;

/**
 * Owns the explicit B↔C mode transitions surfaced through the deck
 * header's collection-mode badge + modal.
 *
 * Companion to {@see DeckCollectionStatusService} (read-side mode
 * inference) and {@see DeckFinalizeService} (planned→built wizard write
 * path). The two transitions here are the ones the original design doc
 * explicitly deferred — promote-without-claiming and the explicit clear-
 * all that resets a sticky-pinned deck back to mode B.
 */
class DeckCollectionModeService
{
    /**
     * Pin the deck to mode C without claiming any stacks.
     *
     * After this the per-card "Assign physical copy" picker becomes
     * reachable and `statusForDeck` ships per-row badges. The deck
     * stays sticky-pinned until {@see clearAssignments} is called.
     *
     * No-op when the deck is already pinned to C — avoids spurious
     * `updated_at` bumps and the side-effects that some listeners might
     * hang off a write.
     */
    public static function promoteToExplicit(Deck $deck): void
    {
        if ($deck->collection_mode === DeckCollectionStatusService::MODE_C) {
            return;
        }

        $deck->update(['collection_mode' => DeckCollectionStatusService::MODE_C]);
    }

    /**
     * Clear every pivot row attached to this deck *and* null the sticky
     * mode pin. The deck cleanly returns to mode B (or A if the user has
     * no stacks). Atomic — either both writes land or neither does.
     *
     * No-op when the deck has no pivots and isn't pinned (already B/A).
     */
    public static function clearAssignments(Deck $deck): void
    {
        DB::transaction(function () use ($deck): void {
            // Bulk-detach via a single DELETE joining through the
            // deck_cards table so we don't pay an N-query roundtrip
            // per deck_card row.
            DB::table('deck_card_card_stack')
                ->whereIn(
                    'deck_card_id',
                    DB::table('deck_cards')
                        ->where('deck_id', $deck->id)
                        ->select('id')
                )
                ->delete();

            if ($deck->collection_mode !== null) {
                $deck->update(['collection_mode' => null]);
            }
        });
    }
}
