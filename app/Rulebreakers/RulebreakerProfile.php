<?php

namespace App\Rulebreakers;

use App\Companions\CompanionProfile;
use App\Enums\Scryfall\ScryfallCardLayout;
use App\Formats\FormatProfile;
use App\Models\Deck;
use App\Models\OracleCard;

/**
 * Base class for the deckbuilding rule each Rulebreaker commander relaxes.
 *
 * "Rulebreaker" is an ability word on legendary creatures that loosen Commander
 * construction for the deck they lead — "A deck with this commander can have
 * Angel cards of any color identity and any basic land cards", and so on.
 *
 * The mirror image of {@see CompanionProfile}, and deliberately
 * shaped like it: one class per card, resolved by name through a registry, so a
 * card's rule is read in one place rather than spread across the validator.
 * The difference is direction. A companion RESTRICTS and so produces
 * violations; a Rulebreaker PERMITS, and so removes violations that would
 * otherwise fire. It therefore hooks into the colour-identity check as an
 * override rather than appearing as a violation type of its own.
 *
 * WHY A WIDENED IDENTITY RATHER THAN A BOOLEAN EXEMPTION. Most of these cards
 * grant "any color identity" to some class of card, which a boolean would model
 * fine. Tolabow does not: it grants ONE nominated colour on top of the
 * commander's, so an instant that is off-identity in two colours is still
 * illegal. Returning the identity a given card is judged against covers both —
 * `WUBRG` for a blanket exemption, `U` + the nominated colour for Tolabow — and
 * keeps the comparison itself in one place in the validator.
 */
abstract class RulebreakerProfile
{
    /** Every colour, i.e. "any color identity". */
    protected const ANY_IDENTITY = 'WUBRG';

    /**
     * i18n key suffix under `pages.deck.rulebreaker.*`, used to explain the
     * relaxation in the legality panel.
     */
    abstract public function messageKey(): string;

    /**
     * The colour identity this card should be judged against, or null to fall
     * back to the deck's own.
     *
     * Returning null rather than the deck identity keeps the caller honest:
     * "this rule has nothing to say about this card" and "this rule permits
     * exactly the deck's colours" are the same outcome but not the same
     * statement, and only the former should be silent.
     *
     * `$baseIdentity` is supplied by the caller rather than read off the deck,
     * and that is deliberate. `decks.colors` is NOT the commander's identity:
     * `DeckCardService::recalculateColorsFromCommandZone()` folds in the
     * companion's colours too, and the column is NULL on a freshly created
     * deck. Widening either of those would be wrong in both directions — too
     * permissive with a companion present, too strict before the column is
     * populated — so the rule widens whatever the validator is actually
     * comparing against.
     *
     * @param  OracleCard  $card  The card being judged, not the commander.
     * @param  Deck  $deck  Loaded with `commanders.oracleCard`.
     * @param  string  $baseIdentity  The identity the deck is judged against.
     */
    abstract public function allowedIdentityFor(OracleCard $card, Deck $deck, string $baseIdentity): ?string;

    /**
     * Whether this commander asks the pilot to nominate a colour, which the
     * deck stores in `decks.rulebreaker_color`.
     *
     * Only Tolabow does today. The frontend uses this to decide whether to
     * offer the picker at all.
     */
    public function requiresColorChoice(): bool
    {
        return false;
    }

    /**
     * Six of the eight Rulebreakers end their rule with "and any basic land
     * cards", letting the deck run off-colour basics. Shared here so each
     * profile states only what makes it different.
     *
     * Matches on name against {@see FormatProfile::BASIC_LANDS}, which already
     * covers Wastes and the Snow-Covered printings, rather than on the Basic
     * supertype — the rule says "basic land cards", and that list is the same
     * one the copy-limit check treats as unlimited.
     */
    protected function isBasicLand(OracleCard $card): bool
    {
        return in_array($card->name, FormatProfile::BASIC_LANDS, true);
    }

    /**
     * True when the CARD's types include any of the given ones.
     *
     * Reads the denormalised `oracle_cards.type_line`, which holds every face
     * joined with " // ", and then narrows to the faces that actually determine
     * the card's types — which is not all of them.
     *
     * A split card genuinely has both halves' types: Fire // Ice is an instant
     * card by either half. Every other multi-faced layout takes its types from
     * the front face alone while the card is not on the stack. Bonecrusher
     * Giant is "Creature — Giant // Instant — Adventure" and is a CREATURE
     * card; its Adventure half is an alternative characteristic used only on
     * the stack. Matching the joined line blindly would hand a red creature to
     * a Tolabow deck that nominated red, which the rule does not permit.
     *
     * A null layout is treated as front-face-only, which is also the correct
     * answer for the single-faced cards that dominate.
     *
     * NOTE for the search-side predicate: the same narrowing has to be applied
     * in SQL, i.e. match `SUBSTRING_INDEX(type_line, ' // ', 1)` except where
     * `layout = 'split'`. Matching the whole column there would reintroduce
     * exactly this bug at the point where cards are offered.
     *
     * @param  array<int, string>  $types
     */
    protected function typeLineMentions(OracleCard $card, array $types): bool
    {
        $line = (string) ($card->type_line ?? '');
        if ($line === '') {
            return false;
        }

        $faces = explode(' // ', $line);
        $relevant = $card->layout === ScryfallCardLayout::Split ? $faces : [$faces[0]];

        foreach ($relevant as $face) {
            foreach ($types as $type) {
                if (str_contains($face, $type)) {
                    return true;
                }
            }
        }

        return false;
    }
}
