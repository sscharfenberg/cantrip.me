<?php

namespace App\Companions;

use App\Models\Deck;
use App\Models\OracleCard;

/**
 * Obosh, the Preypiercer — "Each card in your starting deck has an odd
 * converted mana cost or is a land."
 *
 * Mirror image of {@see GyrudaProfile}: non-lands must have an odd mana
 * value. Uses `oracle_cards.cmc` so split / MDFC cards behave per Scryfall's
 * canonical mana-value rules.
 */
final class OboshProfile extends CompanionProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'obosh';
    }

    /**
     * Flag every non-land main-deck card whose mana value is even.
     */
    public function validate(Deck $deck): ?array
    {
        $ids = [];
        foreach ($this->startingDeckCards($deck) as $deckCard) {
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

    public function failsAddingCard(Deck $deck, OracleCard $card): bool
    {
        return ! $this->isLand($card) && ((int) $card->cmc) % 2 !== 1;
    }
}
