<?php

namespace Tests\Unit\Companions;

use App\Companions\KerugaProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class KerugaProfileTest extends TestCase
{
    use BuildsTestDecks;

    #[Test]
    public function passes_when_every_nonland_has_cmc_three_or_more(): void
    {
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Wrath of God', 4.0, 'Sorcery')),
            $this->makeDeckCard($this->makeOracleCard('Hero\'s Downfall', 3.0, 'Instant')),
            $this->makeDeckCard($this->makeOracleCard('Forest', 0.0, 'Basic Land — Forest')),
        ]);

        $this->assertNull((new KerugaProfile)->validate($deck));
    }

    #[Test]
    public function flags_nonland_with_cmc_below_three(): void
    {
        $bolt = $this->makeOracleCard('Lightning Bolt', 1.0, 'Instant');
        $offending = $this->makeDeckCard($bolt);
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Wrath of God', 4.0, 'Sorcery')),
            $offending,
        ]);

        $this->assertSame(['card_ids' => [$offending->id]], (new KerugaProfile)->validate($deck));
    }
}
