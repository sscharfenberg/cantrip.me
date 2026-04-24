<?php

namespace Tests\Unit\Companions;

use App\Companions\KaheeraProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class KaheeraProfileTest extends TestCase
{
    use BuildsTestDecks;

    #[Test]
    public function passes_with_allowed_tribes_and_noncreatures(): void
    {
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Savannah Lions', 1.0, 'Creature — Cat')),
            $this->makeDeckCard($this->makeOracleCard('Flametongue Kavu', 4.0, 'Creature — Beast')),
            $this->makeDeckCard($this->makeOracleCard('Lightning Bolt', 1.0, 'Instant')),
            $this->makeDeckCard($this->makeOracleCard('Plains', 0.0, 'Basic Land — Plains')),
        ]);

        $this->assertNull((new KaheeraProfile)->validate($deck));
    }

    #[Test]
    public function flags_creature_with_disallowed_subtype(): void
    {
        $human = $this->makeOracleCard('Thalia, Guardian of Thraben', 2.0, 'Legendary Creature — Human Soldier');
        $offending = $this->makeDeckCard($human);
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Savannah Lions', 1.0, 'Creature — Cat')),
            $offending,
        ]);

        $this->assertSame(['card_ids' => [$offending->id]], (new KaheeraProfile)->validate($deck));
    }
}
