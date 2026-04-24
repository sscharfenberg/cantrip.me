<?php

namespace App\Companions;

use App\Models\Deck;

/**
 * Yorion, Sky Nomad — "Your starting deck contains at least 20 cards more than
 * the minimum deck size."
 *
 * Deck-level restriction — returns `current/min` instead of `card_ids`. The
 * minimum tracks the format's own `minDeckSize()`; commanders count toward
 * the total to match how DeckValidator handles `deck_size_min`.
 */
final class YorionProfile extends CompanionProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'yorion';
    }

    /**
     * Sum main-deck copies (respecting `quantity`), add commanders when the
     * format uses them, compare against `minDeckSize() + 20`. Returns the
     * `current/min` pair when short so the legality panel can render the
     * concrete numbers.
     */
    public function validate(Deck $deck): ?array
    {
        $profile = $deck->format->rules();
        $required = $profile->minDeckSize() + 20;

        $count = 0;
        foreach ($this->mainDeckCards($deck) as $deckCard) {
            $count += $deckCard->quantity;
        }
        if ($profile->requiresCommander()) {
            $count += $deck->commanders->count();
        }

        return $count >= $required ? null : ['current' => $count, 'min' => $required];
    }
}
