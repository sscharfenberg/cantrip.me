<?php

namespace App\Rulebreakers;

use App\Companions\CompanionProfile;
use App\Models\Deck;
use App\Models\OracleCard;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base class for the deckbuilding rule each Rulebreaker commander relaxes.
 *
 * "Rulebreaker" is an ability word on legendary creatures that loosen Commander
 * construction for the deck they lead — "A deck with this commander can have
 * Angel cards of any color identity and any basic land cards", and so on.
 *
 * The mirror image of {@see CompanionProfile}, and deliberately shaped like it:
 * one class per card, resolved by name through a registry, so a card's rule is
 * read in one place rather than spread across the validator. The difference is
 * direction. A companion RESTRICTS and so produces violations; a Rulebreaker
 * PERMITS, and so removes violations that would otherwise fire. It therefore
 * hooks into the colour-identity check as an override rather than appearing as
 * a violation type of its own.
 *
 * WHY A WIDENED IDENTITY RATHER THAN A BOOLEAN EXEMPTION. Most of these cards
 * grant "any color identity" to some class of card, which a boolean would model
 * fine. Tolabow does not: it grants ONE nominated colour on top of the
 * commander's, so an instant that is off-identity in two colours is still
 * illegal. Carrying the identity a given card is judged against covers both —
 * `WUBRG` for a blanket exemption, base + nominated for Tolabow — and keeps the
 * comparison itself in one place in the validator.
 *
 * A subclass declares {@see exemptions()} and nothing else. Both consumers read
 * that one list: this class walks it per card, and the deck-card search filter
 * turns it into SQL. See {@see RulebreakerExemption} for why the rule is data.
 */
abstract class RulebreakerProfile
{
    /** Every colour, i.e. "any color identity". */
    protected const ANY_IDENTITY = RulebreakerExemption::ANY_IDENTITY;

    /**
     * i18n key suffix under `pages.deck.rulebreaker.*`, used to explain the
     * relaxation in the legality panel.
     */
    abstract public function messageKey(): string;

    /**
     * Every relaxation this commander grants, most permissive first.
     *
     * Order is the tie-break: {@see allowedIdentityFor()} returns the first
     * match, so where two exemptions could cover the same card the one granting
     * the wider identity must come first. No printed Rulebreaker overlaps today
     * — a basic land is not an instant — but the ordering is cheap to honour and
     * expensive to discover the absence of.
     *
     * `$baseIdentity` is supplied by the caller rather than read off the deck,
     * and that is deliberate. `decks.colors` is not necessarily the commander's
     * identity, so the rule widens whatever the caller is actually comparing
     * against — see {@see Deck::colorIdentity()}.
     *
     * @param  Deck  $deck  Loaded with `commanders.oracleCard`.
     * @param  string  $baseIdentity  The identity the deck is otherwise judged against.
     * @return array<int, RulebreakerExemption>
     */
    abstract public function exemptions(Deck $deck, string $baseIdentity): array;

    /**
     * The colour identity this card should be judged against, or null when this
     * rule has nothing to say about it.
     *
     * Null rather than the deck's own identity keeps the caller honest: "this
     * rule does not cover this card" and "this rule permits exactly the deck's
     * colours" are the same outcome but not the same statement, and only the
     * former should be silent.
     */
    final public function allowedIdentityFor(OracleCard $card, Deck $deck, string $baseIdentity): ?string
    {
        foreach ($this->exemptions($deck, $baseIdentity) as $exemption) {
            if ($exemption->matches($card)) {
                return $exemption->identity;
            }
        }

        return null;
    }

    /**
     * OR this commander's exemptions onto a colour-identity filter, so search
     * offers exactly the cards {@see allowedIdentityFor()} would accept.
     *
     * The caller owns the base identity branch; this adds one branch per
     * exemption beside it.
     *
     * @param  Builder<OracleCard>  $query  A query rooted at `oracle_cards`.
     */
    final public function applyExemptionsTo(Builder $query, Deck $deck, string $baseIdentity): void
    {
        foreach ($this->exemptions($deck, $baseIdentity) as $exemption) {
            $query->orWhere(function (Builder $q) use ($exemption): void {
                $exemption->applyTo($q);
            });
        }
    }

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
     * The pilot's nominated colour, normalised, or null when unusable.
     *
     * Uppercased and whitelisted because the column is a bare nullable char(1)
     * with nothing yet writing it: a stored lowercase 'r' would fail every
     * comparison against a WUBRG identity, so the widening would silently do
     * nothing and the pilot would see violations with no visible cause. The
     * picker's request should still validate the value; this is so a bad one
     * degrades loudly rather than invisibly.
     */
    final protected function nominatedColor(Deck $deck): ?string
    {
        $chosen = strtoupper((string) ($deck->rulebreaker_color ?? ''));

        if ($chosen === '' || ! str_contains(self::ANY_IDENTITY, $chosen)) {
            return null;
        }

        return $chosen;
    }
}
