<?php

namespace App\Companions;

use App\Models\Deck;

/**
 * Keruga, the Macrosage — "Each card in your starting deck has converted mana
 * cost 3 or greater, or is a land."
 *
 * Comparison is on `oracle_cards.cmc`, so `{X}` spells register as their
 * printed base cost (X=0). Cards with effective cost below 3 when X is
 * chosen larger still pass the deckbuilding check, matching the rule's
 * "mana value" reading.
 */
final class KerugaProfile extends CompanionProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'keruga';
    }

    /**
     * Flag every non-land main-deck card whose mana value is strictly
     * below 3.
     */
    public function validate(Deck $deck): ?array
    {
        $ids = [];
        foreach ($this->mainDeckCards($deck) as $deckCard) {
            $oracle = $deckCard->oracleCard;
            if ($this->isLand($oracle)) {
                continue;
            }
            if ((float) $oracle->cmc >= 3.0) {
                continue;
            }
            $ids[] = $deckCard->id;
        }

        return $ids === [] ? null : ['card_ids' => $ids];
    }
}
