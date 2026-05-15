<?php

namespace App\Services;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the per-deck "collection integration" mode and per-card
 * status that drive collection-aware UI on the deck show page.
 *
 * Three modes, explicitly chosen by the user via the collection-mode
 * modal (the {@see User::$collection_integration_enabled} master switch
 * is a global UI gate that overrides every deck to effective mode A
 * while it's off):
 *
 *   A — off. No badges, no claim UI, no implicit coverage. New decks
 *       start here.
 *   B — implicit deckbox. Coverage is inferred from `decks.container_id`
 *       (cards in that container count as covered). Mode-B *badge
 *       rendering* is gated on the controller side: the implicit-status
 *       payload only ships when `decks.container_id` is set so the
 *       per-row "in this deckbox / elsewhere" count has an anchor.
 *   C — explicit assignment. Per-card pivot rows in
 *       `deck_card_card_stack` drive coverage. Switching C → B/A
 *       cascade-deletes every pivot row attached to this deck (see
 *       {@see DeckCollectionModeService::setMode}).
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
     *
     * The user-level master switch overrides the per-deck choice — while
     * it's off, every deck reports effective mode A regardless of its
     * stored `collection_mode`. The stored value is preserved so flipping
     * the master switch back on restores each deck to its prior mode.
     */
    public static function effectiveMode(User $user, Deck $deck): string
    {
        if (! $user->collection_integration_enabled) {
            return self::MODE_A;
        }

        return $deck->collection_mode ?? self::MODE_A;
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
     * Per-deck-card "implicit deckbox" counts for mode B.
     *
     * For each deck_card row, partition the user's owned stacks of the
     * matching printing into:
     *
     *  - `in_deckbox`   — stacks whose `container_id` matches the deck's
     *                     `container_id` (the implicit-deckbox anchor).
     *  - `elsewhere`    — stacks of the same printing in any other
     *                     container (or unsorted).
     *  - `missing`      — `max(0, deck_card.quantity - (in_deckbox + elsewhere))`.
     *
     * Wrong-printing copies are deliberately not counted. Mode B is
     * per-printing — if the user wants to surface alt-printing
     * coverage, mode C's per-card picker is the path.
     *
     * Counts are at face value: the same 4-stack of a printing shared
     * by two split-row deck_cards (rare but legal) shows up as 4 on
     * each row. Each row's `missing` math still uses its own
     * `quantity`, so each row's signal stays correct in isolation —
     * the user reading both rows gets a total that double-counts the
     * stack, which is an acceptable V1 ambiguity.
     *
     * Caller must check {@see effectiveMode} === MODE_B first; this
     * method does not re-check ownership or container presence.
     *
     * @return array<string, array{in_deckbox: int, elsewhere: int, missing: int}>
     *                                                                             Keyed by deck_card_id.
     */
    public static function implicitStatusForDeck(Deck $deck): array
    {
        $userId = $deck->user_id;
        $deckContainerId = $deck->container_id;

        $deckCardRows = DeckCard::query()
            ->where('deck_id', $deck->id)
            ->get(['id', 'default_card_id', 'quantity']);

        if ($deckCardRows->isEmpty()) {
            return [];
        }

        $printingIds = $deckCardRows->pluck('default_card_id')->unique()->values();

        $stacks = DB::table('card_stacks')
            ->where('user_id', $userId)
            ->whereIn('default_card_id', $printingIds)
            ->get(['default_card_id', 'amount', 'container_id']);

        $byPrinting = [];
        foreach ($stacks as $row) {
            $printingId = $row->default_card_id;
            $byPrinting[$printingId] ??= ['in_deckbox' => 0, 'elsewhere' => 0];
            if ($row->container_id === $deckContainerId) {
                $byPrinting[$printingId]['in_deckbox'] += (int) $row->amount;
            } else {
                $byPrinting[$printingId]['elsewhere'] += (int) $row->amount;
            }
        }

        $result = [];
        foreach ($deckCardRows as $dc) {
            $counts = $byPrinting[$dc->default_card_id] ?? ['in_deckbox' => 0, 'elsewhere' => 0];
            $owned = $counts['in_deckbox'] + $counts['elsewhere'];
            $result[$dc->id] = [
                'in_deckbox' => $counts['in_deckbox'],
                'elsewhere' => $counts['elsewhere'],
                'missing' => max(0, (int) $dc->quantity - $owned),
            ];
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
