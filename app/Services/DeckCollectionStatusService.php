<?php

namespace App\Services;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\User;
use Illuminate\Database\Query\Builder;
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
 *
 * {@see availabilityForDeck} sits alongside those two and answers a
 * different question for *planned* decks — "can my collection cover
 * this slot?" — with a three-value taxonomy that ignores the deck's
 * mode entirely. Planned decks show availability instead of the
 * mode-B/C badges; finished and archived decks show the mode badges.
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
     * Planned-deck availability states. Deliberately a separate, coarser
     * taxonomy from the five mode-C statuses above: a planned deck asks
     * "can my collection cover this slot?", not "which stack backs it?".
     */
    public const AVAILABILITY_AVAILABLE = 'available';

    public const AVAILABILITY_PARTIAL = 'partial';

    public const AVAILABILITY_UNAVAILABLE = 'unavailable';

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
     * Per-deck-card *availability* for a planned deck.
     *
     * Answers the planning question — "can my collection cover this
     * slot, or do I have to buy / trade for it?" — and is deliberately
     * independent of the deck's collection-integration mode: a planned
     * deck reports availability whether it sits in mode A, B or C. The
     * caller gates on `decks.state` and on the user-level master switch.
     *
     * A stack counts as *available* to this deck unless another deck has
     * a hold on it. Two kinds of hold, mirroring the two tracking modes:
     *
     *  - explicit — a `deck_card_card_stack` pivot row tying the stack to
     *    a deck_card of *another* deck. Pivots pointing at this deck are
     *    this deck's own copies and stay available.
     *  - implicit — the stack sits in a container that is another deck's
     *    `decks.container_id`. Any other deck counts, whatever its mode:
     *    a binder designated as a deck's deckbox holds that deck's cards
     *    regardless of whether the user switched tracking on for it.
     *
     * Per deck_card row the available copies are split into `exact`
     * (the row's own printing) and `other` (any other printing of the
     * same oracle card), and the state follows from the row's quantity:
     *
     *  - `available`   — `exact >= quantity`. The printing in the deck
     *                    list is fully covered.
     *  - `partial`     — something is available but not that: too few
     *                    copies of the printing, other printings only,
     *                    or a mix of both.
     *  - `unavailable` — no free copy of any printing. `blocked` then
     *                    tells the tooltip whether the user owns copies
     *                    that other decks hold, or none at all.
     *
     * `blocked` is the total held by other decks across every printing
     * of the oracle card and is reported in all three states.
     *
     * Counts are at face value, exactly as {@see implicitStatusForDeck}
     * documents: two deck_card rows of the same printing in this deck
     * each see the whole free pool rather than a share of it.
     *
     * @return array<string, array{state: string, needed: int, exact: int, other: int, blocked: int}>
     *                                                                                                Keyed by deck_card_id.
     */
    public static function availabilityForDeck(Deck $deck): array
    {
        $deckCardRows = DeckCard::query()
            ->where('deck_id', $deck->id)
            ->get(['id', 'oracle_card_id', 'default_card_id', 'quantity']);

        if ($deckCardRows->isEmpty()) {
            return [];
        }

        $oracleIds = $deckCardRows->pluck('oracle_card_id')->unique()->values()->all();

        ['free' => $freeByOracle, 'held' => $heldByOracle] = self::partitionCopies($deck, $oracleIds);

        $result = [];
        foreach ($deckCardRows as $dc) {
            $free = $freeByOracle[$dc->oracle_card_id] ?? [];
            $exact = $free[$dc->default_card_id] ?? 0;
            $other = array_sum($free) - $exact;
            $needed = (int) $dc->quantity;

            $result[$dc->id] = [
                'state' => match (true) {
                    $exact >= $needed => self::AVAILABILITY_AVAILABLE,
                    $exact + $other > 0 => self::AVAILABILITY_PARTIAL,
                    default => self::AVAILABILITY_UNAVAILABLE,
                },
                'needed' => $needed,
                'exact' => $exact,
                'other' => $other,
                'blocked' => $heldByOracle[$dc->oracle_card_id] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Free copies of the given oracle cards' printings, from this deck's
     * point of view: `oracle id → printing id → copies`.
     *
     * "Free" is {@see availabilityForDeck}'s definition, shared so the two
     * features can never drift: a copy counts unless another deck holds it,
     * either through a `deck_card_card_stack` pivot row or by sitting in a
     * container that is another deck's `decks.container_id`. Copies this
     * deck itself holds stay free — they are its own.
     *
     * Each oracle's printings come back **newest first** (set release date,
     * then printing id), so a caller breaking a tie by taking the first of
     * several equal maxima gets the newest printing without sorting again.
     *
     * Honours the collection-integration master switch, unlike the private
     * partition underneath: this is the entry point for callers outside the
     * deck page, which have no controller-level gate of their own.
     *
     * @param  list<string>  $oracleIds
     * @return array<string, array<string, int>>
     */
    public static function freeCopiesForOracles(Deck $deck, array $oracleIds): array
    {
        if ($oracleIds === [] || ! ($deck->user?->collection_integration_enabled ?? false)) {
            return [];
        }

        return self::partitionCopies($deck, $oracleIds)['free'];
    }

    /**
     * Narrow a `default_cards` query to printings this deck has a free copy
     * of — the SQL twin of {@see partitionCopies}'s PHP rule.
     *
     * Same definition, expressed twice because the two are asked different
     * questions: the partition needs per-printing amounts out of rows it has
     * already fetched, while this has to constrain a query *before* its
     * LIMIT so a filtered search still returns a full page. **Change one and
     * you must change the other** — `QuickAddPrintingPreferenceTest` pins
     * them to the same answer on the same fixture for exactly that reason.
     *
     * A printing survives when the owner has at least one stack of it that
     * is neither pivoted to a deck_card of another deck nor sitting in a
     * container that is another deck's `decks.container_id`. This deck's own
     * claims and its own deckbox do not disqualify anything.
     *
     * The caller owns the master-switch gate: this is a query fragment, and
     * silently declining to filter would widen a result set the user asked
     * to narrow.
     */
    public static function constrainToFreePrintings(Builder $query, Deck $deck): void
    {
        $query->whereExists(function (Builder $stacks) use ($deck): void {
            $stacks->from('card_stacks')
                ->whereColumn('card_stacks.default_card_id', 'default_cards.id')
                ->where('card_stacks.user_id', $deck->user_id)
                ->whereNotExists(function (Builder $claims) use ($deck): void {
                    $claims->from('deck_card_card_stack')
                        ->join('deck_cards', 'deck_cards.id', '=', 'deck_card_card_stack.deck_card_id')
                        ->whereColumn('deck_card_card_stack.card_stack_id', 'card_stacks.id')
                        ->where('deck_cards.deck_id', '!=', $deck->id);
                })
                ->where(function (Builder $unreserved) use ($deck): void {
                    $unreserved->whereNull('card_stacks.container_id')
                        ->orWhereNotIn('card_stacks.container_id', function (Builder $boxes) use ($deck): void {
                            $boxes->from('decks')
                                ->select('container_id')
                                ->where('user_id', $deck->user_id)
                                ->where('id', '!=', $deck->id)
                                ->whereNotNull('container_id');
                        });
                });
        });
    }

    /**
     * Split the owner's copies of the given oracle cards into the ones this
     * deck may use and the ones another deck holds.
     *
     * One batched read of every owned stack of those oracle cards,
     * left-joined through the pivot so explicit holds come back in the same
     * pass. A stack claimed by several deck_cards fans out into several
     * rows; the fold collapses them back per stack before summing, so an
     * amount is never counted twice.
     *
     * Rows arrive newest-printing-first and the `free` map is built in that
     * order, which is what lets {@see freeCopiesForOracles} promise a
     * newest-first iteration order to its callers.
     *
     * Deliberately ungated: {@see availabilityForDeck}'s caller checks the
     * master switch before it ever gets here, and re-checking would put the
     * same gate in two places.
     *
     * @param  list<string>  $oracleIds
     * @return array{free: array<string, array<string, int>>, held: array<string, int>}
     */
    private static function partitionCopies(Deck $deck, array $oracleIds): array
    {
        // Containers designated as some *other* deck's deckbox. Flipped
        // to a lookup so the per-stack test below is an isset() rather
        // than an in_array() scan.
        $reservedContainerIds = array_flip(
            DB::table('decks')
                ->where('user_id', $deck->user_id)
                ->where('id', '!=', $deck->id)
                ->whereNotNull('container_id')
                ->distinct()
                ->pluck('container_id')
                ->all()
        );

        $rows = DB::table('card_stacks')
            ->join('default_cards', 'default_cards.id', '=', 'card_stacks.default_card_id')
            ->join('sets', 'sets.id', '=', 'default_cards.set_id')
            ->leftJoin('deck_card_card_stack', 'deck_card_card_stack.card_stack_id', '=', 'card_stacks.id')
            ->leftJoin('deck_cards', 'deck_cards.id', '=', 'deck_card_card_stack.deck_card_id')
            ->where('card_stacks.user_id', $deck->user_id)
            ->whereIn('default_cards.oracle_id', $oracleIds)
            ->orderByDesc('sets.released_at')
            ->orderByDesc('default_cards.id')
            ->get([
                'card_stacks.id as stack_id',
                'card_stacks.default_card_id as printing_id',
                'card_stacks.amount as amount',
                'card_stacks.container_id as container_id',
                'default_cards.oracle_id as oracle_id',
                'deck_cards.deck_id as claimed_by_deck_id',
            ]);

        $stacks = [];
        foreach ($rows as $row) {
            $stacks[$row->stack_id] ??= [
                'printing_id' => $row->printing_id,
                'oracle_id' => $row->oracle_id,
                'amount' => (int) $row->amount,
                'held' => $row->container_id !== null && isset($reservedContainerIds[$row->container_id]),
            ];

            if ($row->claimed_by_deck_id !== null && $row->claimed_by_deck_id !== $deck->id) {
                $stacks[$row->stack_id]['held'] = true;
            }
        }

        $free = [];
        $held = [];
        foreach ($stacks as $stack) {
            if ($stack['held']) {
                $held[$stack['oracle_id']] = ($held[$stack['oracle_id']] ?? 0) + $stack['amount'];

                continue;
            }
            $free[$stack['oracle_id']][$stack['printing_id']] =
                ($free[$stack['oracle_id']][$stack['printing_id']] ?? 0) + $stack['amount'];
        }

        return ['free' => $free, 'held' => $held];
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
