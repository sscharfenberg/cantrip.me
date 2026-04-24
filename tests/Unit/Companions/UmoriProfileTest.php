<?php

namespace Tests\Unit\Companions;

use App\Companions\UmoriProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UmoriProfileTest extends TestCase
{
    use BuildsTestDecks;

    #[Test]
    public function passes_when_all_nonlands_share_a_type(): void
    {
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Savannah Lions', 1.0, 'Creature — Cat')),
            $this->makeDeckCard($this->makeOracleCard('Serra Angel', 5.0, 'Creature — Angel')),
            $this->makeDeckCard($this->makeOracleCard('Plains', 0.0, 'Basic Land — Plains')),
        ]);

        $this->assertNull((new UmoriProfile)->validate($deck));
    }

    #[Test]
    public function passes_when_shared_type_spans_hybrid_cards(): void
    {
        $deck = $this->makeDeck([
            $this->makeDeckCard($this->makeOracleCard('Dryad Arbor', 0.0, 'Land Creature — Forest Dryad')),
            $this->makeDeckCard($this->makeOracleCard('Savannah Lions', 1.0, 'Creature — Cat')),
            $this->makeDeckCard($this->makeOracleCard('Forest', 0.0, 'Basic Land — Forest')),
        ]);

        $this->assertNull((new UmoriProfile)->validate($deck));
    }

    #[Test]
    public function flags_every_nonland_when_types_do_not_intersect(): void
    {
        $creature = $this->makeDeckCard($this->makeOracleCard('Savannah Lions', 1.0, 'Creature — Cat'));
        $instant = $this->makeDeckCard($this->makeOracleCard('Lightning Bolt', 1.0, 'Instant'));
        $land = $this->makeDeckCard($this->makeOracleCard('Plains', 0.0, 'Basic Land — Plains'));
        $deck = $this->makeDeck([$creature, $instant, $land]);

        $result = (new UmoriProfile)->validate($deck);

        $this->assertNotNull($result);
        $this->assertEqualsCanonicalizing([$creature->id, $instant->id], $result['card_ids']);
    }
}
