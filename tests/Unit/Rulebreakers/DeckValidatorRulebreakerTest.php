<?php

namespace Tests\Unit\Rulebreakers;

use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\OracleCard;
use App\Services\DeckValidator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wiring coverage: the Rulebreaker override has to reach
 * {@see DeckValidator::validate()}, not merely work in isolation.
 *
 * These assert on the `color_identity` violation alone. The synthetic decks
 * carry no legalities rows, so every card also trips `pool_legality` — that is
 * expected and irrelevant here, and asserting on the specific violation type
 * keeps this test about the override rather than about fixture completeness.
 *
 * No database: every relation the validator reads is pre-hydrated, and with
 * one copy of each card the copy-limit branch never reaches
 * `hasUnlimitedCopiesRule()`, which is the only path that would lazy-load.
 */
class DeckValidatorRulebreakerTest extends TestCase
{
    use BuildsRulebreakerDecks;

    /**
     * @return array<int, string> deck_card ids flagged for colour identity
     */
    private function colorIdentityViolations(Deck $deck): array
    {
        foreach (DeckValidator::validate($deck) as $violation) {
            if ($violation['type'] === 'color_identity') {
                return $violation['card_ids'];
            }
        }

        return [];
    }

    /**
     * Pre-set every relation the validator touches so nothing lazy-loads.
     *
     * @param  array<int, OracleCard>  $cards
     */
    private function deckWith(?OracleCard $commander, array $cards, ?string $colors, ?string $chosen = null): Deck
    {
        $deckCards = [];
        foreach ($cards as $card) {
            $card->setRelation('legalities', new Collection);
            $card->setRelation('faces', new Collection);
            $deckCards[] = $this->makeDeckCard($card, DeckZone::Main);
        }

        if ($commander !== null) {
            $commander->setRelation('legalities', new Collection);
            $commander->setRelation('faces', new Collection);
        }

        $deck = $this->makeDeck($commander, $deckCards, colors: $colors, rulebreakerColor: $chosen);
        $deck->setRelation('companion', null);

        return $deck;
    }

    /**
     * The baseline the override has to change: without a Rulebreaker, an
     * off-identity instant is flagged, as it should be.
     */
    #[Test]
    public function an_ordinary_commander_still_flags_an_off_identity_instant(): void
    {
        $talrand = $this->makeOracleCard('Talrand, Sky Summoner', 'Legendary Creature — Merfolk Wizard', 'U');
        $bolt = $this->makeOracleCard('Lightning Bolt', 'Instant', 'R');

        $deck = $this->deckWith($talrand, [$bolt], colors: 'U');

        $this->assertCount(1, $this->colorIdentityViolations($deck));
    }

    #[Test]
    public function tolabow_with_a_nominated_colour_no_longer_flags_that_instant(): void
    {
        $bolt = $this->makeOracleCard('Lightning Bolt', 'Instant', 'R');

        $deck = $this->deckWith($this->tolabow(), [$bolt], colors: 'U', chosen: 'R');

        $this->assertSame([], $this->colorIdentityViolations($deck));
    }

    /**
     * The widening is by ONE colour. A URG instant is outside U+R and stays
     * flagged — the case that separates a widened identity from an exemption.
     */
    #[Test]
    public function tolabow_still_flags_an_instant_outside_the_widened_identity(): void
    {
        $bolt = $this->makeOracleCard('Lightning Bolt', 'Instant', 'R');
        $cinder = $this->makeOracleCard('Wort, the Raidmother', 'Instant', 'URG');

        $deck = $this->deckWith($this->tolabow(), [$bolt, $cinder], colors: 'U', chosen: 'R');

        $flagged = $this->colorIdentityViolations($deck);
        $this->assertCount(1, $flagged);
        $this->assertSame($cinder->id, $deck->deckCards->firstWhere('id', $flagged[0])->oracle_card_id);
    }

    /**
     * The nominated colour reaches instants and sorceries only — a red creature
     * is still illegal in a mono-blue deck.
     */
    #[Test]
    public function tolabow_still_flags_an_off_identity_creature(): void
    {
        $goblin = $this->makeOracleCard('Goblin Guide', 'Creature — Goblin Scout', 'R');

        $deck = $this->deckWith($this->tolabow(), [$goblin], colors: 'U', chosen: 'R');

        $this->assertCount(1, $this->colorIdentityViolations($deck));
    }

    #[Test]
    public function tolabow_permits_an_off_colour_basic_land_without_any_choice(): void
    {
        $mountain = $this->makeOracleCard('Mountain', 'Basic Land — Mountain', 'R');

        $deck = $this->deckWith($this->tolabow(), [$mountain], colors: 'U');

        $this->assertSame([], $this->colorIdentityViolations($deck));
    }

    /**
     * A Rulebreaker whose rule is not modelled yet must validate exactly as an
     * ordinary commander does — the safe direction to be wrong in while the
     * set is still in preview.
     */
    #[Test]
    public function an_unmodelled_rulebreaker_validates_like_an_ordinary_commander(): void
    {
        $whtz = $this->makeOracleCard('Whtz, the Bibliophile', 'Legendary Creature — Homunculus', 'WU');
        $bolt = $this->makeOracleCard('Lightning Bolt', 'Instant', 'R');

        $deck = $this->deckWith($whtz, [$bolt], colors: 'WU');

        $this->assertCount(1, $this->colorIdentityViolations($deck));
    }
}
