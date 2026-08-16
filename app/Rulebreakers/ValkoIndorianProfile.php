<?php

namespace App\Rulebreakers;

use App\Models\Deck;

/**
 * Valko Indorian — "Rulebreaker — A deck with this commander can have
 * Phyrexian cards of any color identity and any basic land cards."
 *
 * "Phyrexian" is a creature subtype. Note it is NOT the same thing as Phyrexian
 * MANA in a cost, which this rule says nothing about — the exemption reads the
 * type line only, so a card with {W/P} in its cost gets nothing from it.
 */
final class ValkoIndorianProfile extends RulebreakerProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'valko_indorian';
    }

    /**
     * {@inheritDoc}
     */
    public function exemptions(Deck $deck, string $baseIdentity): array
    {
        $exemptions = [
            new RulebreakerExemption(identity: self::ANY_IDENTITY, types: ['Phyrexian']),
        ];

        // "and any basic land cards" — an outright grant, independent of the
        // type clause above, so it is a second exemption rather than a
        // condition on the first.
        $exemptions[] = new RulebreakerExemption(identity: self::ANY_IDENTITY, basicLands: true);

        return $exemptions;
    }
}
