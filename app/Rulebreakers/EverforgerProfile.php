<?php

namespace App\Rulebreakers;

use App\Models\Deck;

/**
 * The Everforger — "Rulebreaker — A deck with this commander can have artifact
 * creature and Equipment cards of any color identity and any basic land
 * cards."
 *
 * "artifact creature and Equipment" is a union, not an intersection: an
 * Equipment is rarely a creature, so reading it as "both" would grant almost
 * nothing. Two needles on one exemption, which the matcher ORs.
 *
 * "Artifact Creature" is matched as the adjacent pair it appears as in a type
 * line ("Legendary Artifact Creature — Construct"), so a plain artifact does
 * not qualify.
 */
final class EverforgerProfile extends RulebreakerProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'everforger';
    }

    /**
     * {@inheritDoc}
     */
    public function exemptions(Deck $deck, string $baseIdentity): array
    {
        $exemptions = [
            new RulebreakerExemption(identity: self::ANY_IDENTITY, types: ['Artifact Creature', 'Equipment']),
        ];

        // "and any basic land cards" — an outright grant, independent of the
        // type clause above, so it is a second exemption rather than a
        // condition on the first.
        $exemptions[] = new RulebreakerExemption(identity: self::ANY_IDENTITY, basicLands: true);

        return $exemptions;
    }
}
