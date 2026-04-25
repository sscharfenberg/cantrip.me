<?php

namespace App\Companions;

use App\Models\Deck;
use App\Models\OracleCard;

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
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'lurrus';
    }

    /**
     * Flag every permanent card in the main deck with mana value 3 or more.
     * Non-permanents (Instant, Sorcery) are skipped entirely.
     */
    public function validate(Deck $deck): ?array
    {
        $ids = [];
        foreach ($this->startingDeckCards($deck) as $deckCard) {
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

    public function failsAddingCard(Deck $deck, OracleCard $card): bool
    {
        return $this->isPermanent($card) && (float) $card->cmc > 2.0;
    }
}
