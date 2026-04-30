<?php

namespace App\Services;

use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the per-deck "collection integration" mode and per-card
 * status that drive collection-aware UI on the deck show page.
 *
 * Three modes are inferred from data (never explicitly toggled by the
 * user beyond the {@see User::$collection_integration_enabled} master
 * switch):
 *
 *   A — no collection: user has zero card_stacks (or has opted out via
 *       the master switch).
 *   B — implicit deckbox: user has card_stacks but this deck has not
 *       been pinned to mode C and has no pivot rows.
 *   C — explicit assignment: the deck is pinned via `decks.collection_mode`
 *       OR currently has at least one pivot row in `deck_card_card_stack`.
 *
 * Mode C is **sticky**: once the wizard claims at least one stack for
 * a deck, `decks.collection_mode` is set to 'C' and the deck stays in
 * C even if every claimed stack is later deleted from the collection.
 * Per the design doc, C→B is rare and lives in deck settings as a
 * future "clear all collection assignments" action — never via implicit
 * cascade.
 *
 * The five per-card status values returned by {@see statusForDeck}
 * mirror the design-doc taxonomy. Resolution priority (highest →
 * lowest) when multiple matching stacks exist:
 *
 *   claimed_for_this_deck > available > claimed_by_other_deck
 *     > wrong_printing > not_owned
 */
class DeckCollectionStatusService
{
    public const STATUS_CLAIMED_FOR_THIS_DECK = 'claimed_for_this_deck';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_CLAIMED_BY_OTHER_DECK = 'claimed_by_other_deck';

    public const STATUS_WRONG_PRINTING = 'wrong_printing';

    public const STATUS_NOT_OWNED = 'not_owned';

    public const MODE_A = 'A';

    public const MODE_B = 'B';

    public const MODE_C = 'C';

    /**
     * Determine which of the three collection-integration modes applies
     * to this user/deck combination.
     */
    public static function effectiveMode(User $user, Deck $deck): string
    {
        if (! $user->collection_integration_enabled) {
            return self::MODE_A;
        }

        $stackCount = CardStack::query()
            ->where('user_id', $user->id)
            ->count();

        if ($stackCount === 0) {
            return self::MODE_A;
        }

        // Sticky pin: a deck that's previously been promoted to C stays
        // in C even if every pivot row was later cascade-deleted via
        // stack removal. Without this, deleting any claimed stack would
        // silently drop all of the deck's status badges.
        if ($deck->collection_mode === self::MODE_C) {
            return self::MODE_C;
        }

        $pivotCount = DB::table('deck_card_card_stack')
            ->join('deck_cards', 'deck_cards.id', '=', 'deck_card_card_stack.deck_card_id')
            ->where('deck_cards.deck_id', $deck->id)
            ->count();

        return $pivotCount > 0 ? self::MODE_C : self::MODE_B;
    }

    /**
     * Map each deck_card on this deck to its collection status.
     *
     * Single batched query: pulls every owned stack that matches any of
     * the deck's `oracle_card_id`s (covers both exact-printing and
     * wrong-printing cases) plus the deck-membership of those stacks.
     * Status is then resolved per deck_card row in PHP.
     *
     * @return array<string, string> Keyed by deck_card_id.
     */
    public static function statusForDeck(Deck $deck): array
    {
        $userId = $deck->user_id;

        $deckCardRows = DeckCard::query()
            ->where('deck_id', $deck->id)
            ->get(['id', 'oracle_card_id', 'default_card_id']);

        if ($deckCardRows->isEmpty()) {
            return [];
        }

        $oracleIds = $deckCardRows->pluck('oracle_card_id')->unique()->values();

        // For every owned stack matching any of the deck's oracle cards,
        // collect: stack id, default_card_id, oracle_card_id, and the
        // pivot target — *both* deck_id and deck_card_id. The deck_card_id
        // is what distinguishes "claimed_for_this_deck" (stack tied to
        // this exact deck_card row) from a sibling claim within the same
        // deck (stack tied to a different deck_card in the same deck).
        // The sibling case happens after partial-coverage auto-split:
        // the claimed row carries the pivot, the leftover row doesn't,
        // and we don't want the leftover to inherit the sibling's badge.
        // One query, one read; status resolution happens in PHP below.
        $stacks = DB::table('card_stacks')
            ->leftJoin('default_cards', 'default_cards.id', '=', 'card_stacks.default_card_id')
            ->leftJoin('deck_card_card_stack', 'deck_card_card_stack.card_stack_id', '=', 'card_stacks.id')
            ->leftJoin('deck_cards', 'deck_cards.id', '=', 'deck_card_card_stack.deck_card_id')
            ->where('card_stacks.user_id', $userId)
            ->whereIn('default_cards.oracle_id', $oracleIds)
            ->get([
                'card_stacks.id as stack_id',
                'card_stacks.default_card_id as stack_default_card_id',
                'default_cards.oracle_id as stack_oracle_card_id',
                'deck_cards.deck_id as claimed_by_deck_id',
                'deck_cards.id as claimed_by_deck_card_id',
            ]);

        // Group by oracle_card_id, then by default_card_id, so per-row
        // resolution can ask both "is there a same-printing stack?" and
        // "is there any-printing stack of the same oracle card?".
        $byOracle = [];
        foreach ($stacks as $row) {
            $oracleId = $row->stack_oracle_card_id;
            $defaultId = $row->stack_default_card_id;
            $byOracle[$oracleId] ??= [];
            $byOracle[$oracleId][$defaultId] ??= [];
            $byOracle[$oracleId][$defaultId][] = $row;
        }

        $result = [];
        foreach ($deckCardRows as $dc) {
            $result[$dc->id] = self::resolveStatus(
                $byOracle[$dc->oracle_card_id] ?? [],
                $dc->default_card_id,
                $dc->id,
                $deck->id,
            );
        }

        return $result;
    }

    /**
     * Walk the relevant stacks for one deck_card and pick the
     * highest-priority status.
     *
     * Resolution order (per same-printing stack):
     *  1. Pivoted to *this* deck_card → `claimed_for_this_deck`.
     *  2. Pivoted to nothing → `available`.
     *  3. Pivoted to a deck_card in *another* deck → `claimed_by_other_deck`.
     *  4. Pivoted to a sibling deck_card in *this same* deck → silently
     *     dropped (treated as consumed). The partial-coverage auto-split
     *     case relies on this: the leftover row must not inherit the
     *     claimed sibling's status.
     *
     * Falling through the same-printing checks means there's no usable
     * same-printing stack, so we report wrong-printing or not-owned
     * based on whether any other-printing stack of the same oracle card
     * exists.
     *
     * @param  array<string, array<int, object>>  $stacksByDefault  Owned stacks of the same oracle card, grouped by default_card_id.
     */
    private static function resolveStatus(array $stacksByDefault, string $defaultCardId, string $thisDeckCardId, string $thisDeckId): string
    {
        // No owned stacks at all for this oracle card.
        if ($stacksByDefault === []) {
            return self::STATUS_NOT_OWNED;
        }

        // Same-printing stacks first — they decide between claimed-here,
        // available, and claimed-elsewhere.
        $samePrinting = $stacksByDefault[$defaultCardId] ?? [];
        if ($samePrinting !== []) {
            $claimedByThisCard = false;
            $hasFree = false;
            $claimedByOtherDeck = false;

            foreach ($samePrinting as $row) {
                if ($row->claimed_by_deck_id === null) {
                    $hasFree = true;
                } elseif ($row->claimed_by_deck_card_id === $thisDeckCardId) {
                    $claimedByThisCard = true;
                } elseif ($row->claimed_by_deck_id !== $thisDeckId) {
                    $claimedByOtherDeck = true;
                }
                // else: pivoted to a sibling deck_card in the same deck —
                // intentionally not counted toward any positive state.
            }

            if ($claimedByThisCard) {
                return self::STATUS_CLAIMED_FOR_THIS_DECK;
            }
            if ($hasFree) {
                return self::STATUS_AVAILABLE;
            }
            if ($claimedByOtherDeck) {
                return self::STATUS_CLAIMED_BY_OTHER_DECK;
            }
            // All same-printing stacks are consumed by sibling rows in
            // this same deck. Same-printing exists but isn't usable for
            // this row — fall through to wrong_printing / not_owned
            // based on what other printings the user has.
        }

        // Other-printing stacks of the same oracle card exist? Then
        // wrong_printing is the actionable hint (swap the printing).
        // Otherwise the user has nothing left to back this row → not_owned.
        foreach ($stacksByDefault as $printingId => $rows) {
            if ($printingId !== $defaultCardId && $rows !== []) {
                return self::STATUS_WRONG_PRINTING;
            }
        }

        return self::STATUS_NOT_OWNED;
    }
}
