<?php

namespace Tests\Unit\Companions;

use App\Companions\LurrusProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LurrusProfileTest extends TestCase
{
    use BuildsTestDecks;

    #[Test]
    public function passes_when_all_permanents_are_cmc_two_or_less(): void
    {
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Monastery Swiftspear', 1.0, 'Creature — Human Monk')),
            $this->makeDeckCard($this->makeOracleCard('Sol Ring', 1.0, 'Artifact')),
            $this->makeDeckCard($this->makeOracleCard('Lightning Bolt', 1.0, 'Instant')),
            $this->makeDeckCard($this->makeOracleCard('Wrath of God', 4.0, 'Sorcery')),
            $this->makeDeckCard($this->makeOracleCard('Forest', 0.0, 'Basic Land — Forest')),
        ]);

        $this->assertNull((new LurrusProfile)->validate($deck));
    }

    #[Test]
    public function flags_permanent_above_cmc_two(): void
    {
        $bigCreature = $this->makeOracleCard('Serra Angel', 5.0, 'Creature — Angel');
        $offending = $this->makeDeckCard($bigCreature);
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Wrath of God', 4.0, 'Sorcery')),
            $offending,
        ]);

        $this->assertSame(['card_ids' => [$offending->id]], (new LurrusProfile)->validate($deck));
    }
}
