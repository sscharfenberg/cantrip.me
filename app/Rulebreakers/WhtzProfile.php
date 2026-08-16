<?php

namespace App\Rulebreakers;

use App\Models\Deck;

/**
 * Whtz, the Bibliophile — "Rulebreaker — A deck with this commander has no
 * maximum deck size."
 *
 * The odd one out. Every other Rulebreaker relaxes COLOUR IDENTITY for some
 * class of card, which is why the shared contract is a list of exemptions
 * carrying an identity. Whtz relaxes deck SIZE and says nothing about colour,
 * so it grants no exemptions at all and overrides {@see removesMaxDeckSize()}
 * instead.
 *
 * Only the maximum goes. The format minimum still applies — "no maximum deck
 * size" removes the ceiling, it does not excuse a 40-card Commander deck — and
 * singleton is untouched, so the extra cards still have to be distinct.
 */
final class WhtzProfile extends RulebreakerProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'whtz';
    }

    /**
     * {@inheritDoc}
     */
    public function exemptions(Deck $deck, string $baseIdentity): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function removesMaxDeckSize(): bool
    {
        return true;
    }
}
