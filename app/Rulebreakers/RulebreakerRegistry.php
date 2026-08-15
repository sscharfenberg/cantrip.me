<?php

namespace App\Rulebreakers;

use App\Companions\CompanionRegistry;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\OracleCard;

/**
 * Registry of the Rulebreaker commanders.
 *
 * Resolves by name rather than UUID so references survive Scryfall re-imports
 * that regenerate oracle card IDs — the same reason
 * {@see CompanionRegistry} does.
 *
 * {@see NAMES} lists all eight cards, but {@see profileFor()} only maps the
 * ones whose rule is implemented. The two are deliberately allowed to
 * disagree: the cards are from Mystery Booster Commander Edition, which does
 * not release until 2026-11-09, and their oracle text is still preview-season
 * wording that Wizards may re-template before then. Naming a card here without
 * modelling its rule says "known, not yet built" — {@see isRulebreaker()}
 * reports it, and the deck is validated as though the card were an ordinary
 * commander, which is the safe direction to be wrong in.
 */
final class RulebreakerRegistry
{
    /**
     * Oracle names of the eight Rulebreaker cards, with the relaxation each
     * one grants.
     */
    public const NAMES = [
        // "can have any land cards"
        'Grizzlegom, Hurloon Hero',
        // "creature cards with mana value 7 or greater of any color identity"
        'Maular, the Next Evolution',
        // "Angel cards of any color identity"
        'Seluma, Light of Aysen',
        // "artifact creature and Equipment cards of any color identity"
        'The Everforger',
        // "Aura cards of any color identity"
        'The Unluckiest Planeswalker',
        // instants/sorceries may include one nominated colour
        'Tolabow, Loch Rascal',
        // "Phyrexian cards of any color identity"
        'Valko Indorian',
        // "has no maximum deck size"
        'Whtz, the Bibliophile',
    ];

    public static function isRulebreaker(OracleCard $card): bool
    {
        return in_array($card->name, self::NAMES, true);
    }

    /**
     * The profile for a given oracle card, or null when the card is not a
     * Rulebreaker or its rule is not modelled yet.
     */
    public static function profileFor(OracleCard $card): ?RulebreakerProfile
    {
        return match ($card->name) {
            'Tolabow, Loch Rascal' => new TolabowProfile,
            default => null,
        };
    }

    /**
     * The profile governing a deck, resolved from its command zone.
     *
     * Returns the first modelled Rulebreaker found. Two commanders can share a
     * command zone through partner, but no Rulebreaker printed so far has
     * partner, so the case cannot arise yet and is not guessed at — if one ever
     * does, the rules would have to be combined rather than a first match won,
     * and that decision belongs with the card that forces it.
     *
     * Expects `commanders.oracleCard` to be loaded; performs no queries.
     */
    public static function forDeck(Deck $deck): ?RulebreakerProfile
    {
        foreach ($deck->commanders as $deckCard) {
            $oracle = $deckCard->oracleCard;
            if ($oracle === null) {
                continue;
            }
            $profile = self::profileFor($oracle);
            if ($profile !== null) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * The command-zone row whose card is a Rulebreaker, modelled or not.
     *
     * Separate from {@see forDeck()} because the UI needs to know a Rulebreaker
     * is present in order to explain it, including for the cards whose rules
     * are not built yet.
     */
    public static function commanderRowFor(Deck $deck): ?DeckCard
    {
        foreach ($deck->commanders as $deckCard) {
            $oracle = $deckCard->oracleCard;
            if ($oracle !== null && self::isRulebreaker($oracle)) {
                return $deckCard;
            }
        }

        return null;
    }
}
