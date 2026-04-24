<?php

namespace Tests\Unit\Companions;

use App\Companions\GyrudaProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GyrudaProfileTest extends TestCase
{
    use BuildsTestDecks;

    #[Test]
    public function passes_with_only_even_cmc_nonlands_and_lands(): void
    {
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Counterspell', 2.0, 'Instant')),
            $this->makeDeckCard($this->makeOracleCard('Wrath of God', 4.0, 'Sorcery')),
            $this->makeDeckCard($this->makeOracleCard('Island', 0.0, 'Basic Land — Island')),
        ]);

        $this->assertNull((new GyrudaProfile)->validate($deck));
    }

    #[Test]
    public function flags_nonland_with_odd_cmc(): void
    {
        $bolt = $this->makeOracleCard('Lightning Bolt', 1.0, 'Instant');
        $offending = $this->makeDeckCard($bolt);
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Counterspell', 2.0, 'Instant')),
            $offending,
        ]);

        $result = (new GyrudaProfile)->validate($deck);

        $this->assertSame(['card_ids' => [$offending->id]], $result);
    }

    #[Test]
    public function fails_adding_nonland_with_odd_cmc(): void
    {
        $profile = new GyrudaProfile;
        $deck = $this->makeDeck([]);
        $bolt = $this->makeOracleCard('Lightning Bolt', 1.0, 'Instant');
        $counter = $this->makeOracleCard('Counterspell', 2.0, 'Instant');
        $land = $this->makeOracleCard('Plains', 0.0, 'Basic Land — Plains');

        $this->assertTrue($profile->failsAddingCard($deck, $bolt));
        $this->assertFalse($profile->failsAddingCard($deck, $counter));
        $this->assertFalse($profile->failsAddingCard($deck, $land));
    }
}
