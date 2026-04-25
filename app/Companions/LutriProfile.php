<?php

namespace App\Companions;

use App\Formats\FormatProfile;
use App\Models\Deck;

/**
 * Lutri, the Spellchaser — "Your starting deck contains no more than one of
 * each card other than basic lands."
 *
 * Only basic lands are exempt. The "a deck can have any number of" wording
 * (Relentless Rats, Shadowborn Apostle, …) overrides the four-copy rule but
 * not this singleton rule.
 */
final class LutriProfile extends CompanionProfile
{
    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'lutri';
    }

    /**
     * Total up copies per oracle_card_id across the main deck, then flag
     * every row whose oracle card appears more than once and is not a basic
     * land. A single row with `quantity = 2` still violates — Lutri's rule is
     * about total copies, not about distinct deck_card rows.
     */
    public function validate(Deck $deck): ?array
    {
        $startingCards = $this->startingDeckCards($deck);

        $totals = [];
        foreach ($startingCards as $deckCard) {
            $totals[$deckCard->oracle_card_id] = ($totals[$deckCard->oracle_card_id] ?? 0) + $deckCard->quantity;
        }

        $ids = [];
        foreach ($startingCards as $deckCard) {
            if (($totals[$deckCard->oracle_card_id] ?? 0) <= 1) {
                continue;
            }
            if (in_array($deckCard->oracleCard->name, FormatProfile::BASIC_LANDS, true)) {
                continue;
            }
            $ids[] = $deckCard->id;
        }

        return $ids === [] ? null : ['card_ids' => $ids];
    }
}
