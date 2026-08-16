<?php

namespace Tests\Unit\Rulebreakers;

use App\Enums\Scryfall\ScryfallCardLayout;
use App\Models\Deck;
use App\Rulebreakers\EverforgerProfile;
use App\Rulebreakers\GrizzlegomProfile;
use App\Rulebreakers\MaularProfile;
use App\Rulebreakers\RulebreakerExemption;
use App\Rulebreakers\RulebreakerProfile;
use App\Rulebreakers\SelumaProfile;
use App\Rulebreakers\UnluckiestPlaneswalkerProfile;
use App\Rulebreakers\ValkoIndorianProfile;
use App\Rulebreakers\WhtzProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The seven Rulebreakers other than Tolabow.
 *
 * Six grant "any color identity" to a class of card plus off-colour basics, so
 * the interesting assertions are about WHICH cards the class covers — and just
 * as importantly which it does not, since a too-loose type match silently
 * legalises cards the rule never mentioned.
 *
 * Whtz is the odd one out and is tested for granting no exemptions at all.
 */
class RulebreakerProfilesTest extends TestCase
{
    use BuildsRulebreakerDecks;

    /** Resolve what identity a profile would judge the given card against. */
    private function identityFor(object $profile, string $name, string $typeLine, ?string $ci = null, float $cmc = 1.0, ScryfallCardLayout $layout = ScryfallCardLayout::Normal): ?string
    {
        $deck = $this->makeDeck(null, colors: 'G');
        $card = $this->makeOracleCard($name, $typeLine, $ci, $cmc, $layout);

        return $profile->allowedIdentityFor($card, $deck, 'G');
    }

    /**
     * "any land cards" — every land, basics included, so unlike the other six
     * this one needs no separate basic-land exemption.
     */
    #[Test]
    public function grizzlegom_permits_any_land(): void
    {
        $p = new GrizzlegomProfile;

        $this->assertSame('WUBRG', $this->identityFor($p, 'Shivan Reef', 'Land', 'UR'));
        $this->assertSame('WUBRG', $this->identityFor($p, 'Mountain', 'Basic Land — Mountain', 'R'));
        $this->assertSame('WUBRG', $this->identityFor($p, 'Dryad Arbor', 'Land Creature — Dryad', 'G'));
        $this->assertNull($this->identityFor($p, 'Lightning Bolt', 'Instant', 'R'));
    }

    /**
     * The false positive that forced whole-word matching: "Lander" is a real
     * subtype, and Lander Rizzi is an artifact creature, not a land.
     */
    #[Test]
    public function grizzlegom_does_not_permit_a_lander(): void
    {
        $this->assertNull($this->identityFor(
            new GrizzlegomProfile,
            'Lander Rizzi',
            'Legendary Artifact Creature — Lander Rogue',
            'R',
        ));
    }

    #[Test]
    public function maular_permits_only_big_creatures(): void
    {
        $p = new MaularProfile;

        $this->assertSame('WUBRG', $this->identityFor($p, 'Colossus', 'Creature — Golem', 'W', 8.0));
        $this->assertSame('WUBRG', $this->identityFor($p, 'Exactly Seven', 'Creature — Giant', 'U', 7.0));
        $this->assertNull($this->identityFor($p, 'Savannah Lions', 'Creature — Cat', 'W', 1.0));
        // A seven-drop that is not a creature gets nothing.
        $this->assertNull($this->identityFor($p, 'Big Spell', 'Sorcery', 'B', 9.0));
        // ...and the basics clause still applies.
        $this->assertSame('WUBRG', $this->identityFor($p, 'Island', 'Basic Land — Island', 'U', 0.0));
    }

    #[Test]
    public function seluma_permits_angels(): void
    {
        $p = new SelumaProfile;

        $this->assertSame('WUBRG', $this->identityFor($p, 'Serra Angel', 'Creature — Angel', 'W', 5.0));
        $this->assertSame('WUBRG', $this->identityFor($p, 'Angel Warrior', 'Creature — Angel Warrior', 'B', 4.0));
        $this->assertNull($this->identityFor($p, 'Grizzly Bears', 'Creature — Bear', 'G', 2.0));
        $this->assertSame('WUBRG', $this->identityFor($p, 'Swamp', 'Basic Land — Swamp', 'B', 0.0));
    }

    /**
     * "artifact creature and Equipment" is a union — an Equipment is rarely a
     * creature, so reading it as an intersection would grant almost nothing.
     */
    #[Test]
    public function everforger_permits_artifact_creatures_and_equipment(): void
    {
        $p = new EverforgerProfile;

        $this->assertSame('WUBRG', $this->identityFor($p, 'Bomb Courier', 'Artifact Creature — Construct', 'R', 3.0));
        $this->assertSame('WUBRG', $this->identityFor($p, 'Skullclamp', 'Artifact — Equipment', null, 1.0));
        // A plain artifact is neither.
        $this->assertNull($this->identityFor($p, 'Sol Ring', 'Artifact', null, 1.0));
        // Nor is a nonartifact creature.
        $this->assertNull($this->identityFor($p, 'Grizzly Bears', 'Creature — Bear', 'G', 2.0));
    }

    #[Test]
    public function the_unluckiest_planeswalker_permits_auras(): void
    {
        $p = new UnluckiestPlaneswalkerProfile;

        $this->assertSame('WUBRG', $this->identityFor($p, 'Pacifism', 'Enchantment — Aura', 'W', 2.0));
        $this->assertNull($this->identityFor($p, 'Rhystic Study', 'Enchantment', 'U', 3.0));
    }

    #[Test]
    public function valko_permits_phyrexians(): void
    {
        $p = new ValkoIndorianProfile;

        $this->assertSame('WUBRG', $this->identityFor($p, 'Phyrexian Obliterator', 'Creature — Phyrexian Horror', 'B', 4.0));
        $this->assertNull($this->identityFor($p, 'Grizzly Bears', 'Creature — Bear', 'G', 2.0));
    }

    /**
     * Whtz relaxes deck SIZE, not colour identity, so it must grant nothing
     * per-card — otherwise it would quietly widen what the deck may contain.
     */
    #[Test]
    public function whtz_grants_no_colour_exemptions_and_lifts_the_deck_size_cap(): void
    {
        $p = new WhtzProfile;
        $deck = $this->makeDeck(null, colors: 'WU');

        $this->assertSame([], $p->exemptions($deck, 'WU'));
        $this->assertNull($this->identityFor($p, 'Mountain', 'Basic Land — Mountain', 'R', 0.0));
        $this->assertTrue($p->removesMaxDeckSize());
    }

    /** Every other Rulebreaker leaves the deck-size cap alone. */
    #[Test]
    public function the_other_profiles_leave_the_deck_size_cap_alone(): void
    {
        foreach ([new GrizzlegomProfile, new MaularProfile, new SelumaProfile, new EverforgerProfile,
            new UnluckiestPlaneswalkerProfile, new ValkoIndorianProfile] as $profile) {
            $this->assertFalse($profile->removesMaxDeckSize(), $profile::class);
        }
    }

    /**
     * Adventure cards are creature cards. Seluma grants Angels, so an Angel
     * whose Adventure half is an instant must still be judged as the Angel it
     * is — the layout narrowing has to hold for every profile, not just the
     * one it was written for.
     */
    #[Test]
    public function the_layout_narrowing_applies_to_every_profile(): void
    {
        $this->assertNull($this->identityFor(
            new UnluckiestPlaneswalkerProfile,
            'Not An Aura',
            'Creature — Giant // Aura — Adventure',
            'R',
            3.0,
            ScryfallCardLayout::Adventure,
        ));
    }

    /** The shared basic-land clause covers Wastes and the snow printings. */
    #[Test]
    public function the_basic_land_clause_covers_wastes_and_snow_basics(): void
    {
        $p = new SelumaProfile;

        foreach (['Wastes', 'Snow-Covered Forest'] as $name) {
            $this->assertSame(
                RulebreakerExemption::ANY_IDENTITY,
                $this->identityFor($p, $name, 'Basic Land', 'G', 0.0),
                $name,
            );
        }
    }

    /**
     * The validator unions every matching exemption, because the search filter
     * ORs them. First-match-wins would let the two disagree whenever a card
     * matched a narrow exemption listed before a wider one — the validator
     * flagging what search had just offered.
     *
     * Built with a deliberately "wrongly" ordered profile: no printed
     * Rulebreaker overlaps today, so nothing else would catch a regression
     * here.
     */
    #[Test]
    public function a_card_matching_two_exemptions_is_judged_against_both(): void
    {
        $profile = new class extends RulebreakerProfile
        {
            public function messageKey(): string
            {
                return 'test';
            }

            public function exemptions(Deck $deck, string $baseIdentity): array
            {
                return [
                    // Narrow first, wide second — the order that would break
                    // a first-match-wins implementation.
                    new RulebreakerExemption(identity: 'U', types: ['Creature']),
                    new RulebreakerExemption(identity: 'R', types: ['Angel']),
                ];
            }
        };

        $deck = $this->makeDeck(null, colors: 'G');
        $angel = $this->makeOracleCard('Serra Angel', 'Creature — Angel', 'UR', 5.0);

        $this->assertSame('UR', $profile->allowedIdentityFor($angel, $deck, 'G'));
    }

    /** A card matching only one exemption still gets exactly that identity. */
    #[Test]
    public function a_card_matching_one_exemption_gets_that_identity(): void
    {
        $deck = $this->makeDeck(null, colors: 'W');
        $profile = new SelumaProfile;
        $angel = $this->makeOracleCard('Serra Angel', 'Creature — Angel', 'B', 5.0);

        $this->assertSame('WUBRG', $profile->allowedIdentityFor($angel, $deck, 'W'));
    }
}
