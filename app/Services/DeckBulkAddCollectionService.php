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
 * actions menu. For every deck_card that does NOT yet have a pivot row,
 * mints a fresh card_stack of the same printing in `amount = quantity`,
 * attaches it via the deck_card_card_stack pivot, and (optionally) flips
 * the deck to Built.
 *
 * Post-consolidation, command-zone cards (commander / partner / signature
 * spell) and the Magic-keyword companion all live in `deck_cards` too, so
 * a single deck_cards loop covers every card in the deck. The previous
 * incarnation had to special-case the now-defunct `commanders` table and
 * `decks.companion_*` columns; that bookkeeping is gone.
 *
 * Always creates fresh stacks (no merge into existing ones) — the user's
 * intent is "bulk-claim everything in this deck", and merging could hit
 * stacks already claimed by another deck. Defaults are `language=en`,
 * `condition=null`, `finish=nonfoil` — same baseline as the finalize
 * wizard's "bought new" path.
 *
 * Pins `decks.collection_mode = 'C'` whenever at least one pivot row is
 * written, mirroring {@see DeckFinalizeService::persistAssignments}.
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

            foreach ($unclaimed as $deckCard) {
                $stack = CardStack::create([
                    'user_id' => $deck->user_id,
                    'default_card_id' => $deckCard->default_card_id,
                    'container_id' => $containerId,
                    'amount' => $deckCard->quantity,
                    'language' => CardLanguage::En->value,
                    'finish' => Finish::Nonfoil,
                    'condition' => null,
                ]);
                $deckCard->cardStacks()->attach($stack->id);
                $stacksCreated++;
                $cardsAdded += (int) $deckCard->quantity;
            }

            $updates = [];
            if ($stacksCreated > 0 && $deck->collection_mode !== 'C') {
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
}
