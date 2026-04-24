<?php

namespace App\Companions;

use App\Models\Deck;

/**
 * Obosh, the Preypiercer — "Each card in your starting deck has an odd
 * converted mana cost or is a land."
 */
final class OboshProfile extends CompanionProfile
{
    public function messageKey(): string
    {
        return 'obosh';
    }

    public function validate(Deck $deck): ?array
    {
        $ids = [];
        foreach ($this->mainDeckCards($deck) as $deckCard) {
            $oracle = $deckCard->oracleCard;
            if ($this->isLand($oracle)) {
                continue;
            }
            if (((int) $oracle->cmc) % 2 === 1) {
                continue;
            }
            $ids[] = $deckCard->id;
        }

        return $ids === [] ? null : ['card_ids' => $ids];
    }
}
