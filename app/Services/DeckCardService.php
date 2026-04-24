<?php

namespace App\Services;

use App\Models\Deck;

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
     * Recalculate and persist the deck's colors from its cards' mana costs.
     *
     * Only applies to formats that do not enforce color identity (i.e.
     * non-commander formats). Commander-like formats derive their color
     * identity from the command zone, which is immutable during card
     * adds/removes.
     *
     * Unlike color identity (which includes mana symbols from oracle text),
     * deck colors are strictly the union of colored mana symbols appearing
     * in the *mana cost* of each card's faces. Hybrid `{W/U}`, phyrexian
     * `{G/P}`, and monocolored hybrid `{2/W}` all contribute their colored
     * component(s); colorless / generic / variable symbols are ignored.
     */
    public static function recalculateColors(Deck $deck): void
    {
        if ($deck->format->rules()->enforcesColorIdentity()) {
            return;
        }

        $manaCosts = $deck->deckCards()
            ->join('oracle_card_faces', 'deck_cards.oracle_card_id', '=', 'oracle_card_faces.oracle_card_id')
            ->pluck('oracle_card_faces.mana_cost')
            ->filter()
            ->all();

        $colors = self::extractColorsFromManaCosts($manaCosts);

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
