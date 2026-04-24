<?php

namespace App\Companions;

use App\Models\Deck;

/**
 * Lurrus of the Dream-Den — "Each permanent card in your starting deck has
 * converted mana cost 2 or less."
 *
 * Only permanents (Artifact, Creature, Enchantment, Land, Planeswalker,
 * Battle) are checked. Lands have mana value 0 so they always pass; non-
 * permanents (Instant, Sorcery) are unrestricted.
 */
final class LurrusProfile extends CompanionProfile
{
    public function messageKey(): string
    {
        return 'lurrus';
    }

    public function validate(Deck $deck): ?array
    {
        $ids = [];
        foreach ($this->mainDeckCards($deck) as $deckCard) {
            $oracle = $deckCard->oracleCard;
            if (! $this->isPermanent($oracle)) {
                continue;
            }
            if ((float) $oracle->cmc <= 2.0) {
                continue;
            }
            $ids[] = $deckCard->id;
        }

        return $ids === [] ? null : ['card_ids' => $ids];
    }
}
