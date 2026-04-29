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
 *   B — implicit deckbox: user has card_stacks but this deck has no
 *       pivot rows in `deck_card_card_stack`.
 *   C — explicit assignment: this deck has at least one pivot row.
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
        // collect: stack id, default_card_id, oracle_card_id, and the set
        // of deck_ids it is currently claimed by (via the pivot). One
        // query, one read; status resolution happens in PHP below.
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
                $deck->id,
            );
        }

        return $result;
    }

    /**
     * Walk the relevant stacks for one deck_card and pick the
     * highest-priority status.
     *
     * @param  array<string, array<int, object>>  $stacksByDefault  Owned stacks of the same oracle card, grouped by default_card_id.
     */
    private static function resolveStatus(array $stacksByDefault, string $defaultCardId, string $thisDeckId): string
    {
        // No owned stacks at all for this oracle card.
        if ($stacksByDefault === []) {
            return self::STATUS_NOT_OWNED;
        }

        // Same-printing stacks first — they decide between claimed-here,
        // available, and claimed-elsewhere.
        $samePrinting = $stacksByDefault[$defaultCardId] ?? [];
        if ($samePrinting !== []) {
            $claimedByThis = false;
            $hasFree = false;
            $claimedByOther = false;

            foreach ($samePrinting as $row) {
                if ($row->claimed_by_deck_id === null) {
                    $hasFree = true;
                } elseif ($row->claimed_by_deck_id === $thisDeckId) {
                    $claimedByThis = true;
                } else {
                    $claimedByOther = true;
                }
            }

            if ($claimedByThis) {
                return self::STATUS_CLAIMED_FOR_THIS_DECK;
            }
            if ($hasFree) {
                return self::STATUS_AVAILABLE;
            }
            if ($claimedByOther) {
                return self::STATUS_CLAIMED_BY_OTHER_DECK;
            }
        }

        // Different printings of the same oracle card exist in the
        // collection. The user owns the card just not the printing.
        return self::STATUS_WRONG_PRINTING;
    }
}
