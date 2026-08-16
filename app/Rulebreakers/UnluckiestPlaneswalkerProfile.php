<?php

namespace App\Rulebreakers;

use App\Models\Deck;

/**
 * The Unluckiest Planeswalker — "Rulebreaker — A deck with this commander can
 * have Aura cards of any color identity and any basic land cards."
 *
 * The only Rulebreaker that is itself a planeswalker rather than a creature,
 * which changes nothing here: the command zone holds it either way.
 */
final class UnluckiestPlaneswalkerProfile extends RulebreakerProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'unluckiest_planeswalker';
    }

    /**
     * {@inheritDoc}
     */
    public function exemptions(Deck $deck, string $baseIdentity): array
    {
        $exemptions = [
            new RulebreakerExemption(identity: self::ANY_IDENTITY, types: ['Aura']),
        ];

        // "and any basic land cards" — an outright grant, independent of the
        // type clause above, so it is a second exemption rather than a
        // condition on the first.
        $exemptions[] = new RulebreakerExemption(identity: self::ANY_IDENTITY, basicLands: true);

        return $exemptions;
    }
}
