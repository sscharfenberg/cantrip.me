<?php

namespace App\Companions;

use App\Models\Deck;

/**
 * Kaheera, the Orphanguard — "Each creature card in your starting deck is a
 * Cat, Elemental, Nightmare, Dinosaur, or Beast."
 *
 * Non-creature cards are unrestricted. A creature card passes when its
 * subtypes contain at least one of the allowed tribes (checked across all
 * faces to cover DFCs like Bloodthirsty Cavalier // Bloodsworn Steward).
 */
final class KaheeraProfile extends CompanionProfile
{
    private const ALLOWED_TRIBES = ['Cat', 'Elemental', 'Nightmare', 'Dinosaur', 'Beast'];

    public function messageKey(): string
    {
        return 'kaheera';
    }

    public function validate(Deck $deck): ?array
    {
        $ids = [];
        foreach ($this->mainDeckCards($deck) as $deckCard) {
            $oracle = $deckCard->oracleCard;
            if (! $this->isCreature($oracle)) {
                continue;
            }
            if (array_intersect(self::ALLOWED_TRIBES, $this->subtypes($oracle)) !== []) {
                continue;
            }
            $ids[] = $deckCard->id;
        }

        return $ids === [] ? null : ['card_ids' => $ids];
    }
}
