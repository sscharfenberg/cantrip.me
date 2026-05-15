<?php

namespace App\Services;

use App\Models\Deck;
use Illuminate\Support\Facades\DB;

/**
 * Owns the per-deck explicit-mode setter surfaced through the deck
 * header's collection-mode badge + modal.
 *
 * Companion to {@see DeckCollectionStatusService} (read-side). Three
 * valid modes: 'A' (off), 'B' (implicit), 'C' (explicit). Switching
 * from C to B/A cascade-deletes every `deck_card_card_stack` pivot row
 * attached to this deck inside the same transaction.
 */
class DeckCollectionModeService
{
    /**
     * Set this deck's `collection_mode` to one of A / B / C.
     *
     * When the transition is C → B or C → A, every pivot row in
     * `deck_card_card_stack` attached to this deck is deleted in the
     * same transaction as the column update. Other transitions are
     * pure column writes.
     *
     * No-op when the deck is already in the requested mode — avoids
     * spurious `updated_at` bumps and side-effects on observers.
     */
    public static function setMode(Deck $deck, string $mode): void
    {
        if ($deck->collection_mode === $mode) {
            return;
        }

        $wasExplicit = $deck->collection_mode === DeckCollectionStatusService::MODE_C;

        DB::transaction(function () use ($deck, $mode, $wasExplicit): void {
            if ($wasExplicit) {
                DB::table('deck_card_card_stack')
                    ->whereIn(
                        'deck_card_id',
                        DB::table('deck_cards')
                            ->where('deck_id', $deck->id)
                            ->select('id')
                    )
                    ->delete();
            }

            $deck->update(['collection_mode' => $mode]);
        });
    }
}
