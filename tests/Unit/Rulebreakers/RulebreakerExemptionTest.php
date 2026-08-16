<?php

namespace Tests\Unit\Rulebreakers;

use App\Enums\Scryfall\ScryfallCardLayout;
use App\Models\OracleCard;
use App\Rulebreakers\RulebreakerExemption;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Services\DeckCardSearchServiceTest;

/**
 * {@see RulebreakerExemption} is read by two consumers that must agree —
 * {@see RulebreakerExemption::matches()} judges a card already in the deck,
 * {@see RulebreakerExemption::applyTo()} decides whether search offers it. These
 * cover the matcher; the SQL half is exercised against real data in
 * {@see DeckCardSearchServiceTest}, because it needs
 * MariaDB (SUBSTRING_INDEX / REGEXP).
 */
class RulebreakerExemptionTest extends TestCase
{
    private function card(string $name, string $typeLine, ?string $ci = null, float $cmc = 1.0, ScryfallCardLayout $layout = ScryfallCardLayout::Normal): OracleCard
    {
        $card = new OracleCard;
        $card->name = $name;
        $card->type_line = $typeLine;
        $card->color_identity = $ci;
        $card->cmc = $cmc;
        $card->layout = $layout;

        return $card;
    }

    /**
     * The guard that keeps the two consumers honest. An exemption matching on
     * nothing means "no cards" to matches(), but applyTo() would emit an empty
     * nested where — which Laravel discards — leaving a far wider predicate, or
     * none at all. Refused at construction instead.
     */
    #[Test]
    public function it_refuses_an_exemption_that_matches_on_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RulebreakerExemption(identity: 'WUBRG');
    }

    /**
     * The realistic trigger: Maular grants "creature cards with mana value 7 or
     * greater", and expressing that as a bare minCmc would have search offer
     * every 7-drop in Magic while the validator flagged them all.
     */
    #[Test]
    public function it_refuses_a_min_cmc_exemption_with_no_types(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RulebreakerExemption(identity: 'WUBRG', minCmc: 7.0);
    }

    #[Test]
    public function it_matches_a_card_by_type(): void
    {
        $exemption = new RulebreakerExemption(identity: 'WUBRG', types: ['Instant', 'Sorcery']);

        $this->assertTrue($exemption->matches($this->card('Ponder', 'Sorcery')));
        $this->assertFalse($exemption->matches($this->card('Grizzly Bears', 'Creature — Bear')));
    }

    #[Test]
    public function it_matches_only_the_front_face_outside_split_layouts(): void
    {
        $exemption = new RulebreakerExemption(identity: 'WUBRG', types: ['Instant']);

        $this->assertFalse($exemption->matches($this->card(
            'Bonecrusher Giant', 'Creature — Giant // Instant — Adventure', 'R', 3.0, ScryfallCardLayout::Adventure
        )));
        $this->assertTrue($exemption->matches($this->card(
            'Fire // Ice', 'Instant // Instant', 'UR', 2.0, ScryfallCardLayout::Split
        )));
    }

    #[Test]
    public function it_narrows_a_type_match_by_mana_value(): void
    {
        $exemption = new RulebreakerExemption(identity: 'WUBRG', types: ['Creature'], minCmc: 7.0);

        $this->assertTrue($exemption->matches($this->card('Colossus', 'Creature — Golem', 'W', 8.0)));
        $this->assertFalse($exemption->matches($this->card('Savannah Lions', 'Creature — Cat', 'W', 1.0)));
    }

    #[Test]
    public function it_matches_basic_lands_by_name(): void
    {
        $exemption = new RulebreakerExemption(identity: 'WUBRG', basicLands: true);

        $this->assertTrue($exemption->matches($this->card('Mountain', 'Basic Land — Mountain', 'R', 0.0)));
        $this->assertTrue($exemption->matches($this->card('Wastes', 'Basic Land', null, 0.0)));
        $this->assertFalse($exemption->matches($this->card('Shivan Reef', 'Land', 'UR', 0.0)));
    }

    #[Test]
    public function a_card_with_no_type_line_matches_nothing(): void
    {
        $exemption = new RulebreakerExemption(identity: 'WUBRG', types: ['Instant']);

        $this->assertFalse($exemption->matches($this->card('Mystery', '')));
    }
}
