<?php

namespace Tests\Unit\Rulebreakers;

use App\Rulebreakers\RulebreakerRegistry;
use App\Rulebreakers\TolabowProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * All eight Rulebreakers are named AND modelled, and these hold both halves in
 * place: every name resolves to a profile, and no two names resolve to the
 * same one.
 *
 * The registry still tolerates a name without a profile — that is the safe
 * interim state if Wizards re-templates a rule before the set releases on
 * 2026-11-09 — but it should be a decision, not an oversight, which is what
 * the completeness test is for.
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
     * Every named Rulebreaker now resolves to a profile.
     *
     * This replaces a test that used Whtz as the "known but unmodelled" case,
     * which stopped being true once all eight were built. Asserted over NAMES
     * rather than card-by-card so adding a ninth name without a profile fails
     * here — the registry tolerates that state deliberately, and the point is
     * that it should be a decision rather than an oversight.
     */
    #[Test]
    public function every_named_rulebreaker_resolves_to_a_profile(): void
    {
        foreach (RulebreakerRegistry::NAMES as $name) {
            $card = $this->makeOracleCard($name, 'Legendary Creature — Test', 'U');
            $this->assertNotNull(
                RulebreakerRegistry::profileFor($card),
                "$name is named as a Rulebreaker but resolves to no profile",
            );
        }
    }

    /**
     * Each profile is distinct — a copy-paste in the match arms would
     * otherwise hand one card another's rule, which no other test would catch
     * because both would still "resolve to a profile".
     */
    #[Test]
    public function each_rulebreaker_resolves_to_its_own_profile(): void
    {
        $classes = [];
        foreach (RulebreakerRegistry::NAMES as $name) {
            $card = $this->makeOracleCard($name, 'Legendary Creature — Test', 'U');
            $classes[$name] = RulebreakerRegistry::profileFor($card)::class;
        }

        $this->assertSame(
            count($classes),
            count(array_unique($classes)),
            'two Rulebreakers share a profile class: '.json_encode($classes),
        );
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
     * The row lookup is separate from the profile lookup so the UI can name a
     * Rulebreaker it cannot yet enforce. Nothing is unmodelled today, so this
     * covers the lookup itself; the divergence it exists for is covered by
     * {@see every_named_rulebreaker_resolves_to_a_profile}.
     */
    #[Test]
    public function it_finds_the_command_zone_row_for_a_rulebreaker(): void
    {
        $whtz = $this->makeOracleCard('Whtz, the Bibliophile', 'Legendary Creature — Homunculus', 'WU');
        $deck = $this->makeDeck($whtz, colors: 'WU');

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
