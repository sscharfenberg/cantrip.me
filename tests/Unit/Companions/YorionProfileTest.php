<?php

namespace Tests\Unit\Companions;

use App\Companions\YorionProfile;
use App\Enums\CardFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class YorionProfileTest extends TestCase
{
    use BuildsTestDecks;

    #[Test]
    public function passes_when_deck_is_at_least_twenty_above_format_minimum(): void
    {
        $filler = $this->makeOracleCard('Island', 0.0, 'Basic Land — Island');
        $deckCards = [$this->makeDeckCard($filler, quantity: 80)];
        $deck = $this->makeDeck($deckCards, CardFormat::Legacy);

        $this->assertNull((new YorionProfile)->validate($deck));
    }

    #[Test]
    public function flags_undersized_deck_with_current_and_min(): void
    {
        $filler = $this->makeOracleCard('Island', 0.0, 'Basic Land — Island');
        $deck = $this->makeDeck([$this->makeDeckCard($filler, quantity: 60)], CardFormat::Legacy);

        $this->assertSame(['current' => 60, 'min' => 80], (new YorionProfile)->validate($deck));
    }
}
