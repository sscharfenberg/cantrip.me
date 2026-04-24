<?php

namespace Tests\Unit\Companions;

use App\Companions\LutriProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LutriProfileTest extends TestCase
{
    use BuildsTestDecks;

    #[Test]
    public function passes_with_singleton_nonbasics_and_multiple_basics(): void
    {
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Lightning Bolt', 1.0, 'Instant')),
            $this->makeDeckCard($this->makeOracleCard('Counterspell', 2.0, 'Instant')),
            $this->makeDeckCard($this->makeOracleCard('Island', 0.0, 'Basic Land — Island'), quantity: 20),
        ]);

        $this->assertNull((new LutriProfile)->validate($deck));
    }

    #[Test]
    public function flags_duplicate_nonbasic(): void
    {
        $bolt = $this->makeOracleCard('Lightning Bolt', 1.0, 'Instant');
        $dup1 = $this->makeDeckCard($bolt);
        $dup2 = $this->makeDeckCard($bolt);
        $deck = $this->makeDeck([$dup1, $dup2]);

        $result = (new LutriProfile)->validate($deck);

        $this->assertNotNull($result);
        $this->assertEqualsCanonicalizing([$dup1->id, $dup2->id], $result['card_ids']);
    }

    #[Test]
    public function flags_single_row_with_quantity_two(): void
    {
        $bolt = $this->makeOracleCard('Lightning Bolt', 1.0, 'Instant');
        $row = $this->makeDeckCard($bolt, quantity: 2);
        $deck = $this->makeDeck([$row]);

        $this->assertSame(['card_ids' => [$row->id]], (new LutriProfile)->validate($deck));
    }
}
