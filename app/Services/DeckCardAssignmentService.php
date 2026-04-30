<?php

namespace App\Services;

use App\Models\CardStack;
use App\Models\DeckCard;
use Illuminate\Support\Facades\DB;

/**
 * Owns the post-finalize "assign physical copy" flow exposed by the
 * per-card picker (`DeckCardAssignStackModal`).
 *
 * Companion to {@see DeckFinalizeService}: the wizard runs once at the
 * planned→built transition and may auto-split deck_card rows on partial
 * coverage; this service runs as ongoing maintenance on a built deck and
 * mutates the pivot for a single deck_card row only — no deck_card splits.
 *
 * The picker is replace-style: every call detaches the deck_card's
 * existing pivot rows and attaches the chosen stack (or none, when
 * clearing the assignment). The deck stays sticky-pinned to mode C
 * regardless — per the design doc, C→B is an explicit "clear all
 * assignments" action that's not yet built.
 */
class DeckCardAssignmentService
{
    /**
     * Replace the deck_card's assigned stack(s) with the chosen one.
     *
     * Atomic. Detaches every existing pivot row for `$deckCard`, then —
     * if `$stack` is non-null — attaches it (after splitting it down to
     * the deck_card's `quantity` if the stack is oversized, mirroring
     * {@see DeckFinalizeService}'s wizard behaviour). When `$stack` is
     * null the deck_card is left with no claimed stacks.
     *
     * Caller is responsible for verifying that the stack is owned by the
     * same user, matches the deck_card's printing, and isn't already
     * pivoted to a different deck_card — those checks live in
     * {@see UpdateDeckCardAssignedStacksRequest}.
     */
    public static function replaceAssignedStack(DeckCard $deckCard, ?CardStack $stack): void
    {
        DB::transaction(function () use ($deckCard, $stack): void {
            $deckCard->cardStacks()->detach();

            if ($stack === null) {
                return;
            }

            $stackToAttach = $stack;
            if ($stack->amount > $deckCard->quantity) {
                $stackToAttach = CardStackService::splitStack($stack, (int) $deckCard->quantity);
            }

            $deckCard->cardStacks()->attach($stackToAttach->id);
        });
    }
}
