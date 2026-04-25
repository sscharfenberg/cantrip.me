<?php

namespace App\Companions;

use App\Models\Deck;
use App\Models\OracleCard;

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
    /** Creature subtypes Kaheera's restriction accepts. */
    private const ALLOWED_TRIBES = ['Cat', 'Elemental', 'Nightmare', 'Dinosaur', 'Beast'];

    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'kaheera';
    }

    /**
     * Flag each creature card whose subtypes (across all faces) include none
     * of the allowed tribes. Non-creatures are skipped; a land-creature like
     * Dryad Arbor is still checked as a creature.
     */
    public function validate(Deck $deck): ?array
    {
        $ids = [];
        foreach ($this->startingDeckCards($deck) as $deckCard) {
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

    public function failsAddingCard(Deck $deck, OracleCard $card): bool
    {
        return $this->isCreature($card)
            && array_intersect(self::ALLOWED_TRIBES, $this->subtypes($card)) === [];
    }
}
