<?php

namespace App\Rulebreakers;

use App\Models\Deck;

/**
 * Grizzlegom, Hurloon Hero — "Rulebreaker — A deck with this commander can
 * have any land cards."
 *
 * The simplest of the eight, and the only one with no basic-land clause of its
 * own: "any land cards" already covers basics, so a second exemption would be
 * redundant rather than additive.
 *
 * Matching is on the LAND card type, which is why the shared matcher works on
 * whole words — "Lander" is a real subtype (Lander Rizzi is an artifact
 * creature), and a substring match would legalise it here.
 */
final class GrizzlegomProfile extends RulebreakerProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'grizzlegom';
    }

    /**
     * {@inheritDoc}
     */
    public function exemptions(Deck $deck, string $baseIdentity): array
    {
        $exemptions = [
            new RulebreakerExemption(identity: self::ANY_IDENTITY, types: ['Land']),
        ];

        return $exemptions;
    }
}
