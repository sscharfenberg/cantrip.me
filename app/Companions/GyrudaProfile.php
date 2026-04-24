<?php

namespace App\Companions;

use App\Models\Deck;
use App\Models\OracleCard;

/**
 * Gyruda, Doom of Depths — "Each card in your starting deck has an even
 * converted mana cost or is a land."
 *
 * Uses `oracle_cards.cmc` directly (Scryfall's pre-computed mana value), so
 * split cards get the combined value and MDFCs the front face — both matching
 * the official deckbuilding reading of "mana value" for this restriction.
 */
final class GyrudaProfile extends CompanionProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'gyruda';
    }

    /**
     * Flag every non-land main-deck card whose mana value is odd. A mana
     * value of 0 (typical for lands or cards like Ancestral Vision when
     * reduced) is even and therefore passes — but the land short-circuit
     * runs first anyway.
     */
    public function validate(Deck $deck): ?array
    {
        $ids = [];
        foreach ($this->mainDeckCards($deck) as $deckCard) {
            $oracle = $deckCard->oracleCard;
            if ($this->isLand($oracle)) {
                continue;
            }
            if (((int) $oracle->cmc) % 2 === 0) {
                continue;
            }
            $ids[] = $deckCard->id;
        }

        return $ids === [] ? null : ['card_ids' => $ids];
    }

    public function failsAddingCard(Deck $deck, OracleCard $card): bool
    {
        return ! $this->isLand($card) && ((int) $card->cmc) % 2 !== 0;
    }
}
