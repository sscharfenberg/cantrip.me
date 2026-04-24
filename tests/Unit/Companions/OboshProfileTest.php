<?php

namespace Tests\Unit\Companions;

use App\Companions\OboshProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OboshProfileTest extends TestCase
{
    use BuildsTestDecks;

    #[Test]
    public function passes_with_only_odd_cmc_nonlands_and_lands(): void
    {
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Lightning Bolt', 1.0, 'Instant')),
            $this->makeDeckCard($this->makeOracleCard('Hero\'s Downfall', 3.0, 'Instant')),
            $this->makeDeckCard($this->makeOracleCard('Mountain', 0.0, 'Basic Land — Mountain')),
        ]);

        $this->assertNull((new OboshProfile)->validate($deck));
    }

    #[Test]
    public function flags_nonland_with_even_cmc(): void
    {
        $counter = $this->makeOracleCard('Counterspell', 2.0, 'Instant');
        $offending = $this->makeDeckCard($counter);
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Lightning Bolt', 1.0, 'Instant')),
            $offending,
        ]);

        $this->assertSame(['card_ids' => [$offending->id]], (new OboshProfile)->validate($deck));
    }
}
