<?php

namespace Tests\Unit\Rulebreakers;

use App\Enums\Scryfall\ScryfallCardLayout;
use App\Rulebreakers\TolabowProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tolabow, Loch Rascal — "the color identity of instant and sorcery cards in
 * your deck can include one color of your choice not in your commander's color
 * identity, and your deck can have any basic land cards."
 *
 * The distinction these tests exist to hold onto: the nominated colour WIDENS
 * the identity by one, it does not remove the restriction. A mono-blue Tolabow
 * nominating red may run a UR instant and may not run a URG one.
 */
class TolabowProfileTest extends TestCase
{
    use BuildsRulebreakerDecks;

    private function profile(): TolabowProfile
    {
        return new TolabowProfile;
    }

    #[Test]
    public function it_widens_the_identity_by_the_nominated_colour_for_an_instant(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U', rulebreakerColor: 'R');
        $bolt = $this->makeOracleCard('Lightning Bolt', 'Instant', 'R');

        $this->assertSame('UR', $this->profile()->allowedIdentityFor($bolt, $deck, 'U'));
    }

    #[Test]
    public function it_widens_the_identity_for_a_sorcery_too(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U', rulebreakerColor: 'B');
        $duress = $this->makeOracleCard('Duress', 'Sorcery', 'B');

        $this->assertSame('UB', $this->profile()->allowedIdentityFor($duress, $deck, 'U'));
    }

    /**
     * The rule names instants and sorceries only. A red creature stays illegal
     * in a mono-blue deck no matter what was nominated, and the profile says so
     * by declining to speak rather than by returning the deck's own identity.
     */
    #[Test]
    public function it_says_nothing_about_a_card_that_is_neither_instant_nor_sorcery(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U', rulebreakerColor: 'R');
        $goblin = $this->makeOracleCard('Goblin Guide', 'Creature — Goblin Scout', 'R');

        $this->assertNull($this->profile()->allowedIdentityFor($goblin, $deck, 'U'));
    }

    /**
     * A split card really is both halves — Fire // Ice is an instant card by
     * either — which is why the denormalised column joins every face.
     */
    #[Test]
    public function it_widens_for_a_split_card_with_an_instant_half(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U', rulebreakerColor: 'R');
        $fireIce = $this->makeOracleCard(
            'Fire // Ice', 'Instant // Instant', 'UR', layout: ScryfallCardLayout::Split
        );

        $this->assertSame('UR', $this->profile()->allowedIdentityFor($fireIce, $deck, 'U'));
    }

    /**
     * An Adventure card is NOT an instant card. Bonecrusher Giant is
     * "Creature — Giant // Instant — Adventure", and its card type outside the
     * stack is creature; the Adventure half is an alternative characteristic
     * used only while casting it. Matching the joined line blindly would hand a
     * red creature to a deck that nominated red.
     */
    #[Test]
    public function it_does_not_widen_for_an_adventure_creature(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U', rulebreakerColor: 'R');
        $giant = $this->makeOracleCard(
            'Bonecrusher Giant', 'Creature — Giant // Instant — Adventure', 'R',
            layout: ScryfallCardLayout::Adventure,
        );

        $this->assertNull($this->profile()->allowedIdentityFor($giant, $deck, 'U'));
    }

    /**
     * The front face still counts on a non-split layout: an Adventure whose
     * front face is the sorcery half is a sorcery card.
     */
    #[Test]
    public function it_widens_when_the_front_face_of_a_multi_faced_card_is_a_sorcery(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U', rulebreakerColor: 'B');
        $mdfc = $this->makeOracleCard(
            "Agadeem's Awakening", 'Sorcery // Land', 'B',
            layout: ScryfallCardLayout::ModalDfc,
        );

        $this->assertSame('UB', $this->profile()->allowedIdentityFor($mdfc, $deck, 'U'));
    }

    /**
     * Regression: the widening must be built on the identity the validator is
     * actually comparing against, not on `decks.colors`. That column folds in
     * the COMPANION's colours, so a Tolabow deck running Lurrus would otherwise
     * judge instants against WUB + the nominated colour and wave through cards
     * that are plainly illegal.
     */
    #[Test]
    public function it_widens_the_passed_identity_not_the_decks_colors_column(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'WUB', rulebreakerColor: 'R');
        $bolt = $this->makeOracleCard('Lightning Bolt', 'Instant', 'R');

        $this->assertSame('UR', $this->profile()->allowedIdentityFor($bolt, $deck, 'U'));
    }

    /**
     * The same bug in the other direction: `decks.colors` is NULL on a freshly
     * created deck, which would have judged even a plain blue instant against
     * just the nominated colour and flagged it.
     */
    #[Test]
    public function it_is_unaffected_by_a_null_colors_column(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: null, rulebreakerColor: 'R');
        $counterspell = $this->makeOracleCard('Counterspell', 'Instant', 'U');

        $this->assertSame('UR', $this->profile()->allowedIdentityFor($counterspell, $deck, 'U'));
    }

    /**
     * "your deck can have any basic land cards" — outright, and unaffected by
     * the nominated colour, so it holds even before one is picked.
     */
    #[Test]
    public function it_permits_any_basic_land_outright(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U');
        $mountain = $this->makeOracleCard('Mountain', 'Basic Land — Mountain', 'R');

        $this->assertSame('WUBRG', $this->profile()->allowedIdentityFor($mountain, $deck, 'U'));
    }

    #[Test]
    public function it_permits_wastes_and_snow_covered_basics_too(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U');

        foreach (['Wastes', 'Snow-Covered Swamp'] as $name) {
            $land = $this->makeOracleCard($name, 'Basic Snow Land', 'B');
            $this->assertSame('WUBRG', $this->profile()->allowedIdentityFor($land, $deck, 'U'), $name);
        }
    }

    /**
     * A nonbasic land gets nothing: the clause says "basic land cards", and
     * Grizzlegom is the Rulebreaker that grants any land.
     */
    #[Test]
    public function it_does_not_permit_a_nonbasic_land(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U', rulebreakerColor: 'R');
        $strand = $this->makeOracleCard('Arid Mesa', 'Land', null);

        $this->assertNull($this->profile()->allowedIdentityFor($strand, $deck, 'U'));
    }

    /**
     * Not having chosen yet is a legal state, and grants no widening — the deck
     * is judged exactly as an ordinary mono-blue deck would be.
     */
    #[Test]
    public function it_grants_no_widening_before_a_colour_is_nominated(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U');
        $bolt = $this->makeOracleCard('Lightning Bolt', 'Instant', 'R');

        $this->assertNull($this->profile()->allowedIdentityFor($bolt, $deck, 'U'));
    }

    /**
     * Nominating a colour already in the commander's identity is pointless but
     * not illegal, and must not produce a doubled letter — "UU" would still
     * compare correctly today, but only by accident of the subset check.
     */
    #[Test]
    public function it_does_not_duplicate_a_colour_already_in_the_identity(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U', rulebreakerColor: 'U');
        $counterspell = $this->makeOracleCard('Counterspell', 'Instant', 'U');

        $this->assertSame('U', $this->profile()->allowedIdentityFor($counterspell, $deck, 'U'));
    }

    #[Test]
    public function it_requires_a_colour_choice(): void
    {
        $this->assertTrue($this->profile()->requiresColorChoice());
    }
}
