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
    public function messageKey(): string
    {
        return 'lutri';
    }

    public function validate(Deck $deck): ?array
    {
        $mainCards = $this->mainDeckCards($deck);

        $totals = [];
        foreach ($mainCards as $deckCard) {
            $totals[$deckCard->oracle_card_id] = ($totals[$deckCard->oracle_card_id] ?? 0) + $deckCard->quantity;
        }

        $ids = [];
        foreach ($mainCards as $deckCard) {
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
