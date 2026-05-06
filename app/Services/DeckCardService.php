<?php

namespace App\Services;

use App\Models\Deck;
use App\Models\OracleCard;
use App\Models\OracleCardFace;

/**
 * Manages deck card operations that affect the deck itself.
 *
 * Extracted so that both add and remove flows share the same logic
 * for updating derived deck state (e.g. colors).
 */
final class DeckCardService
{
    /** Canonical WUBRG sort order. */
    private const WUBRG = ['W', 'U', 'B', 'R', 'G'];

    /**
     * Recalculate and persist the deck's colors.
     *
     * Commander-like formats (those that enforce color identity) derive
     * colors from the union of color identities of the command zone —
     * commanders, signature spell, companion. Other formats derive colors
     * from the union of colored mana symbols in their cards' mana costs,
     * folding in the companion (if set) since it's still cast from outside
     * the deck.
     *
     * Hybrid `{W/U}`, phyrexian `{G/P}`, and monocolored hybrid `{2/W}`
     * all contribute their colored component(s); colorless / generic /
     * variable symbols are ignored.
     */
    public static function recalculateColors(Deck $deck): void
    {
        if ($deck->format->rules()->enforcesColorIdentity()) {
            self::recalculateColorsFromCommandZone($deck);

            return;
        }

        $manaCosts = $deck->deckCards()
            ->join('oracle_card_faces', 'deck_cards.oracle_card_id', '=', 'oracle_card_faces.oracle_card_id')
            ->pluck('oracle_card_faces.mana_cost')
            ->all();

        if ($deck->companion_oracle_card_id !== null) {
            $companionCosts = OracleCardFace::query()
                ->where('oracle_card_id', $deck->companion_oracle_card_id)
                ->pluck('mana_cost')
                ->all();
            $manaCosts = array_merge($manaCosts, $companionCosts);
        }

        $colors = self::extractColorsFromManaCosts(array_filter($manaCosts));

        $deck->update(['colors' => $colors === '' ? null : $colors]);
    }

    /**
     * Derive deck colors from the union of color identities of every card
     * currently in the command zone (commanders + signature spell stored
     * via the commanders pivot, plus the deck's companion).
     */
    private static function recalculateColorsFromCommandZone(Deck $deck): void
    {
        $oracleIds = $deck->commanders()->pluck('commanders.oracle_card_id')->all();
        if ($deck->companion_oracle_card_id !== null) {
            $oracleIds[] = $deck->companion_oracle_card_id;
        }

        if ($oracleIds === []) {
            $deck->update(['colors' => null]);

            return;
        }

        $identities = OracleCard::query()
            ->whereIn('id', array_values(array_unique($oracleIds)))
            ->pluck('color_identity')
            ->all();

        $present = [];
        foreach ($identities as $identity) {
            if ($identity === null || $identity === '') {
                continue;
            }
            foreach (str_split($identity) as $component) {
                if (in_array($component, self::WUBRG, true)) {
                    $present[$component] = true;
                }
            }
        }

        $colors = implode('', array_filter(self::WUBRG, fn (string $c): bool => isset($present[$c])));

        $deck->update(['colors' => $colors === '' ? null : $colors]);
    }

    /**
     * Collect the colored components present in the given mana cost strings
     * and return them as a canonical WUBRG-ordered string.
     *
     * Input tokens are Scryfall-style `{...}` symbols:
     *
     *  - `{W}`, `{U}`, `{B}`, `{R}`, `{G}` — single colored
     *  - `{W/U}` hybrid — both colors
     *  - `{G/P}` phyrexian — colored component only
     *  - `{2/W}` monocolored hybrid — colored component only
     *  - `{C}`, `{X}`, `{0}`, `{1}`, `{S}`, … — no color contribution
     *
     * @param  iterable<string>  $manaCosts
     */
    public static function extractColorsFromManaCosts(iterable $manaCosts): string
    {
        $present = [];
        foreach ($manaCosts as $cost) {
            if ($cost === '' || $cost === null) {
                continue;
            }
            preg_match_all('/\{([^}]+)}/', $cost, $matches);
            foreach ($matches[1] as $inner) {
                foreach (explode('/', $inner) as $component) {
                    if (in_array($component, self::WUBRG, true)) {
                        $present[$component] = true;
                    }
                }
            }
        }

        return implode('', array_filter(self::WUBRG, fn (string $c): bool => isset($present[$c])));
    }
}
