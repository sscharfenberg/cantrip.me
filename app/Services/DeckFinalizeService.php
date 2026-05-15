<?php

namespace App\Services;

use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use Illuminate\Support\Facades\DB;

/**
 * Owns the BulkClaim wizard's claim-persistence logic.
 *
 * Single entry point: {@see persistAssignments} writes pivot rows, swaps
 * `deck_cards.default_card_id` to the picked stack's printing when the
 * user chose an alternate printing, runs the auto-split bookkeeping
 * (partial coverage and oversized stacks), optionally pads uncovered
 * slots with a freshly-bought stack, and sets the deck's
 * `container_id` when one was picked in the wizard's bottom dropdown.
 *
 * State transitions are decoupled from claim activity — flipping the
 * deck between planned / built / archived happens via the dedicated
 * `/decks/{deck}/state` endpoint, not here.
 */
class DeckFinalizeService
{
    /**
     * Persist the BulkClaim wizard's assignments. No-op when both
     * `$assignments` and `$buyNew` are empty (still a valid submit).
     *
     * - Each `[deck_card_id => [stack_id, ...]]` entry attaches the
     *   listed stacks via the pivot.
     * - Printing swap: if the user picked a stack of a printing other
     *   than the deck_card's, `deck_cards.default_card_id` is swapped
     *   to the picked stack's printing before any other write — the
     *   deck visually adopts the user's chosen printing and the badge
     *   reads `claimed_for_this_deck` (no `wrong_printing` state). UI
     *   is single-stack-per-row, so all picked stacks for one row
     *   share one printing.
     * - Auto-split #1: when a stack's `amount` exceeds the assigned
     *   slice, the stack is split first via
     *   {@see CardStackService::splitStack} so only the claimed copies
     *   carry the pivot row.
     * - Auto-split #2: when the user assigns N stacks for a deck_card
     *   whose `quantity > N` AND doesn't tick "bought new", the
     *   deck_card is split into a claimed row of `quantity = N`
     *   (carrying the pivot rows) and a leftover row of `quantity =
     *   remainder` (no pivot).
     * - Buy-new pad: rows where `buy_new[deck_card_id] === true` get
     *   any uncovered slots covered by a freshly created (or merged
     *   into an existing matching unsorted stack) card_stack via
     *   {@see CardStackService::addToCollection}. The bought stack
     *   uses the deck_card's *current* printing — after the printing
     *   swap above, that's whatever the user picked.
     *
     * @param  array<string, array<int, string>>  $assignments  Keyed by deck_card_id → list of card_stack_ids.
     * @param  array<string, bool>  $buyNew  Keyed by deck_card_id → "bought new" flag.
     */
    public static function persistAssignments(Deck $deck, array $assignments, array $buyNew, ?string $containerId): void
    {
        DB::transaction(function () use ($deck, $assignments, $buyNew, $containerId): void {
            $anyPivotsWritten = false;

            $touchedDeckCardIds = array_unique(array_merge(
                array_keys($assignments),
                array_keys(array_filter($buyNew)),
            ));

            foreach ($touchedDeckCardIds as $deckCardId) {
                $stackIds = array_values(array_unique(array_filter($assignments[$deckCardId] ?? [])));
                $buyForThisRow = (bool) ($buyNew[$deckCardId] ?? false);
                if ($stackIds === [] && ! $buyForThisRow) {
                    continue;
                }

                $deckCard = DeckCard::query()
                    ->where('id', $deckCardId)
                    ->where('deck_id', $deck->id)
                    ->first();
                if ($deckCard === null) {
                    continue;
                }

                if (self::claimStacksForDeckCard($deckCard, $stackIds, $buyForThisRow, $containerId)) {
                    $anyPivotsWritten = true;
                }
            }

            $updates = [];
            if ($containerId !== null) {
                $updates['container_id'] = $containerId;
            }
            // Defensive auto-pin: should already be 'C' (BulkClaim is
            // gated to mode C in BulkClaimRequest), but keep the write
            // for any other caller that lands here without that gate.
            if ($anyPivotsWritten && $deck->collection_mode !== 'C') {
                $updates['collection_mode'] = 'C';
            }
            if ($updates !== []) {
                $deck->update($updates);
            }
        });
    }

    /**
     * Claim a set of stacks for one deck_card row.
     *
     * Returns true when at least one pivot row was written, so the
     * caller knows whether to bump `decks.collection_mode`. Defensive
     * against `quantity <= 0` — a no-op short-circuit so a malformed
     * deck_card row can't trigger spurious work.
     *
     * @param  array<int, string>  $stackIds
     */
    private static function claimStacksForDeckCard(DeckCard $deckCard, array $stackIds, bool $buyNew, ?string $containerId): bool
    {
        $needed = (int) $deckCard->quantity;
        if ($needed <= 0) {
            return false;
        }

        // Pull picked stacks, then narrow to ones whose printing belongs
        // to the deck_card's oracle card. Alt-printing picks are valid:
        // the printing swap below brings the deck_card in line with the
        // chosen stack before any pivot writes.
        $stacks = CardStack::query()
            ->whereIn('id', $stackIds)
            ->where('user_id', $deckCard->deck->user_id)
            ->with('defaultCard:id,oracle_id')
            ->get()
            ->filter(fn (CardStack $s) => $s->defaultCard?->oracle_id === $deckCard->oracle_card_id);

        // Printing swap (§2 path). Single-stack-per-row UI means all
        // picked stacks share one printing, so swapping to the first
        // is enough. Done before splitStack / buy-new so every
        // subsequent write uses the chosen printing.
        $pickedPrintingId = $stacks->first()?->default_card_id;
        if ($pickedPrintingId !== null && $pickedPrintingId !== $deckCard->default_card_id) {
            $deckCard->update(['default_card_id' => $pickedPrintingId]);
            $deckCard->refresh();
        }

        $alreadyClaimed = self::claimedStackIds($stacks->pluck('id')->all());
        $stacks = $stacks->reject(fn (CardStack $s): bool => in_array($s->id, $alreadyClaimed, true));

        $claimed = 0;
        $stacksToAttach = [];

        foreach ($stacks as $stack) {
            if ($claimed >= $needed) {
                break;
            }

            $remaining = $needed - $claimed;
            if ($stack->amount > $remaining) {
                $split = CardStackService::splitStack($stack, $remaining);
                $stacksToAttach[] = $split->id;
                $claimed += $remaining;
            } else {
                $stacksToAttach[] = $stack->id;
                $claimed += (int) $stack->amount;
            }
        }

        if ($buyNew && $claimed < $needed) {
            $uncovered = $needed - $claimed;
            $bought = CardStackService::addToCollection($deckCard->deck->user, [
                'default_card_id' => $deckCard->default_card_id,
                'amount' => $uncovered,
                'language' => 'en',
                'condition' => null,
                'finish' => 'nonfoil',
                'container_id' => $containerId,
            ]);
            $stacksToAttach[] = $bought['stack']->id;
            $claimed += $uncovered;
        }

        // Dedupe — addToCollection may have merged into a stack the
        // user already picked, in which case the pivot's composite PK
        // would reject the second insert.
        $stacksToAttach = array_values(array_unique($stacksToAttach));

        if ($stacksToAttach === []) {
            return false;
        }

        $targetDeckCard = $deckCard;
        if ($claimed < $needed) {
            // Partial coverage + no buy-new pad: split the deck_card so
            // the leftover slot is its own row, rendering with
            // `not_owned` status.
            $leftover = $needed - $claimed;
            $deckCard->update(['quantity' => $claimed]);
            DeckCard::create([
                'deck_id' => $deckCard->deck_id,
                'oracle_card_id' => $deckCard->oracle_card_id,
                'default_card_id' => $deckCard->default_card_id,
                'category_id' => $deckCard->category_id,
                'zone' => $deckCard->zone->value,
                'quantity' => $leftover,
            ]);
        }

        $targetDeckCard->cardStacks()->attach($stacksToAttach);

        return true;
    }

    /**
     * Find which of the given stack ids already have a pivot row to
     * any deck. Used to silently drop double-claim attempts.
     *
     * @param  array<int, string>  $stackIds
     * @return array<int, string>
     */
    private static function claimedStackIds(array $stackIds): array
    {
        if ($stackIds === []) {
            return [];
        }

        return DB::table('deck_card_card_stack')
            ->whereIn('card_stack_id', $stackIds)
            ->pluck('card_stack_id')
            ->all();
    }
}
