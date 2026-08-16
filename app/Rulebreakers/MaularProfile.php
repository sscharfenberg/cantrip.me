<?php

namespace App\Rulebreakers;

use App\Models\Deck;

/**
 * Maular, the Next Evolution — "Rulebreaker — A deck with this commander can
 * have creature cards with mana value 7 or greater of any color identity and
 * any basic land cards."
 *
 * The only Rulebreaker whose grant is conditioned on something other than a
 * type: the mana-value floor narrows the creature clause rather than standing
 * as a grant of its own. Expressed as `minCmc` ON the type exemption, because
 * an exemption matching on mana value alone would legalise every seven-drop in
 * Magic — which is why {@see RulebreakerExemption} refuses to be constructed
 * that way.
 */
final class MaularProfile extends RulebreakerProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'maular';
    }

    /**
     * {@inheritDoc}
     */
    public function exemptions(Deck $deck, string $baseIdentity): array
    {
        $exemptions = [
            new RulebreakerExemption(identity: self::ANY_IDENTITY, types: ['Creature'], minCmc: 7.0),
        ];

        // "and any basic land cards" — an outright grant, independent of the
        // type clause above, so it is a second exemption rather than a
        // condition on the first.
        $exemptions[] = new RulebreakerExemption(identity: self::ANY_IDENTITY, basicLands: true);

        return $exemptions;
    }
}
