<?php

namespace App\Rulebreakers;

use App\Models\Deck;
use App\Models\OracleCard;

/**
 * Tolabow, Loch Rascal — "Rulebreaker — If Tolabow, Loch Rascal is your
 * commander, the color identity of instant and sorcery cards in your deck can
 * include one color of your choice not in your commander's color identity, and
 * your deck can have any basic land cards."
 *
 * Two separate relaxations, and only the first is unusual.
 *
 * The instant/sorcery clause WIDENS the deck's identity by exactly one colour
 * rather than removing the restriction: mono-blue Tolabow nominating red may
 * run a UR instant, but not a URG one, and not a black one. That is why the
 * profile contract returns an identity to judge against instead of a boolean —
 * see {@see RulebreakerProfile}.
 *
 * The nominated colour is the pilot's, stored on `decks.rulebreaker_color`. It
 * may be unset, which is a legal state and simply grants no widening; the deck
 * is then judged exactly as an ordinary mono-blue deck would be. Whether the
 * nominated colour is actually outside the commander's identity — the card says
 * "not in your commander's color identity" — is a constraint on the CHOICE, not
 * on the cards, so it belongs to the request that stores it rather than here.
 * Nominating a colour already in the identity would be pointless but not
 * illegal, and it widens to the same set either way.
 */
final class TolabowProfile extends RulebreakerProfile
{
    /** Types the nominated colour extends to. */
    private const WIDENED_TYPES = ['Instant', 'Sorcery'];

    /**
     * {@inheritDoc}
     */
    public function messageKey(): string
    {
        return 'tolabow';
    }

    /**
     * {@inheritDoc}
     */
    public function requiresColorChoice(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function allowedIdentityFor(OracleCard $card, Deck $deck, string $baseIdentity): ?string
    {
        // "your deck can have any basic land cards" — an outright exemption,
        // and independent of the nominated colour.
        if ($this->isBasicLand($card)) {
            return self::ANY_IDENTITY;
        }

        if (! $this->typeLineMentions($card, self::WIDENED_TYPES)) {
            return null;
        }

        // Uppercased before comparing. The column is a bare nullable char(1)
        // with nothing yet writing it, and a stored lowercase 'r' would fail
        // every str_contains against a WUBRG identity — the widening would
        // silently do nothing and the pilot would see violations with no
        // visible cause. The picker's request should still validate the value;
        // this is so a bad one degrades loudly rather than invisibly.
        $chosen = strtoupper((string) ($deck->rulebreaker_color ?? ''));
        if ($chosen === '' || ! str_contains(self::ANY_IDENTITY, $chosen)) {
            return null;
        }

        return str_contains($baseIdentity, $chosen) ? $baseIdentity : $baseIdentity.$chosen;
    }
}
