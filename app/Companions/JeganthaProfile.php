<?php

namespace App\Companions;

use App\Models\Deck;
use App\Models\OracleCard;

/**
 * Jegantha, the Wellspring — "No card in your starting deck has more than one
 * of the same colored mana symbol in its mana cost."
 *
 * Per Scryfall ruling, hybrid symbols count as each of their colored
 * components. So `{G/W}{G/W}` violates — two green pips AND two white pips
 * — and `{W/P}{W}` violates since phyrexian-white still counts as white.
 *
 * Monocolored hybrid ("twobrid", `{2/W}`) and phyrexian (`{W/P}`) contribute
 * their single colored component. Generic, colorless, and variable (`{X}`)
 * symbols are ignored.
 *
 * Faces are checked independently: a split card or modal DFC whose front
 * face has `{R}` and whose back face has `{R}` is still legal — the rule
 * looks at the cost you pay to cast a given face.
 */
final class JeganthaProfile extends CompanionProfile
{
    public function messageKey(): string
    {
        return 'jegantha';
    }

    public function validate(Deck $deck): ?array
    {
        $ids = [];
        foreach ($this->mainDeckCards($deck) as $deckCard) {
            if ($this->violates($deckCard->oracleCard)) {
                $ids[] = $deckCard->id;
            }
        }

        return $ids === [] ? null : ['card_ids' => $ids];
    }

    private function violates(OracleCard $card): bool
    {
        foreach ($this->manaCosts($card) as $cost) {
            if ($this->faceViolates($cost)) {
                return true;
            }
        }

        return false;
    }

    private function faceViolates(string $manaCost): bool
    {
        $counts = ['W' => 0, 'U' => 0, 'B' => 0, 'R' => 0, 'G' => 0];

        preg_match_all('/\{([^}]+)}/', $manaCost, $matches);
        foreach ($matches[1] as $inner) {
            $colorsInSymbol = [];
            foreach (explode('/', $inner) as $component) {
                if (isset($counts[$component])) {
                    $colorsInSymbol[$component] = true;
                }
            }
            foreach (array_keys($colorsInSymbol) as $color) {
                $counts[$color]++;
            }
        }

        foreach ($counts as $count) {
            if ($count > 1) {
                return true;
            }
        }

        return false;
    }
}
