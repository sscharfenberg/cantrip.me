<?php

namespace App\Companions;

use App\Models\Deck;

/**
 * Keruga, the Macrosage — "Each card in your starting deck has converted mana
 * cost 3 or greater, or is a land."
 */
final class KerugaProfile extends CompanionProfile
{
    public function messageKey(): string
    {
        return 'keruga';
    }

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
