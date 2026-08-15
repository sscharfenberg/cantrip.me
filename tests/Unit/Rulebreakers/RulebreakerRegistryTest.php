<?php

namespace Tests\Unit\Rulebreakers;

use App\Rulebreakers\RulebreakerRegistry;
use App\Rulebreakers\TolabowProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The registry deliberately knows more cards than it models: all eight
 * Rulebreakers are named, but only the ones whose rule is built map to a
 * profile. These tests hold that distinction in place, because collapsing it
 * in either direction is a silent correctness change — modelling a card whose
 * preview-season wording later shifts, or forgetting one exists.
 */
class RulebreakerRegistryTest extends TestCase
{
    use BuildsRulebreakerDecks;

    #[Test]
    public function it_names_all_eight_rulebreakers(): void
    {
        $this->assertCount(8, RulebreakerRegistry::NAMES);
        $this->assertContains('Tolabow, Loch Rascal', RulebreakerRegistry::NAMES);
        $this->assertContains('Whtz, the Bibliophile', RulebreakerRegistry::NAMES);
    }

    #[Test]
    public function it_recognises_a_rulebreaker_by_name(): void
    {
        $this->assertTrue(RulebreakerRegistry::isRulebreaker($this->tolabow()));
        $this->assertFalse(RulebreakerRegistry::isRulebreaker(
            $this->makeOracleCard('Talrand, Sky Summoner', 'Legendary Creature — Merfolk Wizard', 'U')
        ));
    }

    #[Test]
    public function it_resolves_a_profile_for_a_modelled_card(): void
    {
        $this->assertInstanceOf(TolabowProfile::class, RulebreakerRegistry::profileFor($this->tolabow()));
    }

    /**
     * A named-but-unmodelled card resolves to null on purpose: the deck is then
     * validated as though it were an ordinary commander, which is the safe
     * direction to be wrong in while the set is still in preview.
     */
    #[Test]
    public function it_resolves_null_for_a_named_but_unmodelled_card(): void
    {
        $whtz = $this->makeOracleCard('Whtz, the Bibliophile', 'Legendary Creature — Homunculus', 'WU');

        $this->assertTrue(RulebreakerRegistry::isRulebreaker($whtz));
        $this->assertNull(RulebreakerRegistry::profileFor($whtz));
    }

    #[Test]
    public function it_resolves_null_for_an_ordinary_card(): void
    {
        $this->assertNull(RulebreakerRegistry::profileFor(
            $this->makeOracleCard('Lightning Bolt', 'Instant', 'R')
        ));
    }

    #[Test]
    public function it_finds_the_profile_from_a_decks_command_zone(): void
    {
        $deck = $this->makeDeck($this->tolabow(), colors: 'U');

        $this->assertInstanceOf(TolabowProfile::class, RulebreakerRegistry::forDeck($deck));
    }

    #[Test]
    public function it_returns_null_for_a_deck_led_by_an_ordinary_commander(): void
    {
        $deck = $this->makeDeck(
            $this->makeOracleCard('Talrand, Sky Summoner', 'Legendary Creature — Merfolk Wizard', 'U'),
            colors: 'U',
        );

        $this->assertNull(RulebreakerRegistry::forDeck($deck));
    }

    #[Test]
    public function it_returns_null_for_a_deck_with_an_empty_command_zone(): void
    {
        $this->assertNull(RulebreakerRegistry::forDeck($this->makeDeck(null)));
    }

    /**
     * The UI needs to know a Rulebreaker is present even when its rule is not
     * modelled, so it can still explain the card — hence a lookup separate
     * from {@see RulebreakerRegistry::forDeck()}.
     */
    #[Test]
    public function it_finds_the_command_zone_row_for_an_unmodelled_rulebreaker(): void
    {
        $whtz = $this->makeOracleCard('Whtz, the Bibliophile', 'Legendary Creature — Homunculus', 'WU');
        $deck = $this->makeDeck($whtz, colors: 'WU');

        $this->assertNull(RulebreakerRegistry::forDeck($deck));
        $row = RulebreakerRegistry::commanderRowFor($deck);
        $this->assertNotNull($row);
        $this->assertSame($whtz->id, $row->oracle_card_id);
    }

    #[Test]
    public function it_finds_no_command_zone_row_for_an_ordinary_deck(): void
    {
        $deck = $this->makeDeck(
            $this->makeOracleCard('Talrand, Sky Summoner', 'Legendary Creature — Merfolk Wizard', 'U'),
            colors: 'U',
        );

        $this->assertNull(RulebreakerRegistry::commanderRowFor($deck));
    }
}
