<?php

namespace App\Rulebreakers;

use App\Models\Deck;

/**
 * Seluma, Light of Aysen — "Rulebreaker — A deck with this commander can have
 * Angel cards of any color identity and any basic land cards."
 *
 * "Angel" is a creature SUBTYPE, so it sits after the em dash in the type line
 * — the shared matcher reads the whole (front) face rather than only the type
 * portion, so subtype and type clauses are expressed identically.
 */
final class SelumaProfile extends RulebreakerProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'seluma';
    }

    /**
     * {@inheritDoc}
     */
    public function exemptions(Deck $deck, string $baseIdentity): array
    {
        $exemptions = [
            new RulebreakerExemption(identity: self::ANY_IDENTITY, types: ['Angel']),
        ];

        // "and any basic land cards" — an outright grant, independent of the
        // type clause above, so it is a second exemption rather than a
        // condition on the first.
        $exemptions[] = new RulebreakerExemption(identity: self::ANY_IDENTITY, basicLands: true);

        return $exemptions;
    }
}
