<?php

namespace App\Services;

use App\Enums\CardLanguage;
use App\Enums\DeckState;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Deck;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only "Add all cards to collection" action — surfaced from the deck
 * actions menu. Three passes inside one DB transaction:
 *
 *   1. Mainboard / sideboard via deck_cards: every deck_card without a
 *      pivot row gets a fresh card_stack of `amount = quantity`, attached
 *      via the deck_card_card_stack pivot.
 *   2. Command zone via the `commanders` pivot: each command-zone card
 *      (primary commander, partner, oathbreaker, signature spell — every
 *      row in `commanders` for the deck) gets a stack of `amount = 1`
 *      whenever the user doesn't already own that printing.
 *   3. Companion via `decks.companion_default_card_id`: same "stack of 1
 *      if not already owned" rule.
 *
 * Command-zone and companion stacks are NOT pivot-attached — the schema
 * has no claim mechanism for them (the `deck_card_card_stack` pivot only
 * keys on `deck_cards`). They simply land in the user's collection.
 *
 * Deck-card stacks are always fresh (no merge into existing ones) — the
 * user's intent is "bulk-claim everything in this deck", and merging
 * could hit stacks already claimed by another deck. Command-zone and
 * companion stacks are skipped when a stack of the same printing already
 * exists for the user, to avoid silently bumping `amount` on a curated
 * stack on every re-run.
 *
 * Defaults across all three passes are `language=en`, `condition=null`,
 * `finish=nonfoil` — same baseline as the finalize wizard's "bought new"
 * path.
 *
 * Pins `decks.collection_mode = 'C'` whenever at least one *deck_card*
 * pivot row is written. Command-zone / companion stacks alone don't pin
 * — they aren't claims.
 */
class DeckBulkAddCollectionService
{
    /**
     * @return array{stacks_created: int, cards_added: int}
     */
    public static function addAll(Deck $deck, ?string $containerId, bool $setBuilt): array
    {
        return DB::transaction(function () use ($deck, $containerId, $setBuilt): array {
            $unclaimed = $deck->deckCards()
                ->whereDoesntHave('cardStacks')
                ->where('quantity', '>', 0)
                ->get();

            $stacksCreated = 0;
            $cardsAdded = 0;
            $pivotsWritten = false;

            foreach ($unclaimed as $deckCard) {
                $stack = self::createStack($deck, $deckCard->default_card_id, (int) $deckCard->quantity, $containerId);
                $deckCard->cardStacks()->attach($stack->id);
                $stacksCreated++;
                $cardsAdded += (int) $deckCard->quantity;
                $pivotsWritten = true;
            }

            // Command zone — every row in `commanders` for this deck.
            // Iterates partners/oathbreakers/signature spells too since
            // they all live on the same pivot. Skipped when the user
            // already owns any stack of the chosen printing so re-runs
            // don't multiply rows.
            foreach ($deck->commanders as $oracle) {
                $defaultCardId = $oracle->pivot->default_card_id;
                if ($defaultCardId === null || self::userOwnsPrinting($deck->user_id, $defaultCardId)) {
                    continue;
                }
                self::createStack($deck, $defaultCardId, 1, $containerId);
                $stacksCreated++;
                $cardsAdded += 1;
            }

            // Companion — at most one printing per deck, optional.
            if ($deck->companion_default_card_id !== null
                && ! self::userOwnsPrinting($deck->user_id, $deck->companion_default_card_id)) {
                self::createStack($deck, $deck->companion_default_card_id, 1, $containerId);
                $stacksCreated++;
                $cardsAdded += 1;
            }

            $updates = [];
            if ($pivotsWritten && $deck->collection_mode !== 'C') {
                $updates['collection_mode'] = 'C';
            }
            if ($setBuilt && $deck->state !== DeckState::Built) {
                $updates['state'] = DeckState::Built->value;
            }
            if ($updates !== []) {
                $deck->update($updates);
            }

            return [
                'stacks_created' => $stacksCreated,
                'cards_added' => $cardsAdded,
            ];
        });
    }

    /**
     * Create a fresh card_stack with the bulk-add baseline attributes
     * (language en, nonfoil, no condition). Container is the user's
     * picked container or null for unsorted.
     */
    private static function createStack(Deck $deck, string $defaultCardId, int $amount, ?string $containerId): CardStack
    {
        return CardStack::create([
            'user_id' => $deck->user_id,
            'default_card_id' => $defaultCardId,
            'container_id' => $containerId,
            'amount' => $amount,
            'language' => CardLanguage::En->value,
            'finish' => Finish::Nonfoil,
            'condition' => null,
        ]);
    }

    /**
     * Whether the user already has any card_stack of the given printing,
     * regardless of container / language / finish / condition. Used to
     * skip command-zone and companion entries that the user has already
     * tracked manually, so re-running the bulk add is idempotent for
     * them.
     */
    private static function userOwnsPrinting(string $userId, string $defaultCardId): bool
    {
        return CardStack::query()
            ->where('user_id', $userId)
            ->where('default_card_id', $defaultCardId)
            ->exists();
    }
}
