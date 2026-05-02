<?php

namespace Tests\Unit\Enums;

use App\Enums\Scryfall\ScryfallRelatedComponent;
use App\Services\Scryfall\DefaultCardsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the four `all_parts.component` values Scryfall ships.
 * Used by {@see DefaultCardsService::bufferRelations()}
 * as a `tryFrom` guard — if Scryfall ever adds a fifth, the import
 * silently skips it without breaking, and this test catches the
 * regression once we add the case here.
 */
class ScryfallRelatedComponentTest extends TestCase
{
    #[Test]
    public function it_maps_the_four_known_scryfall_components(): void
    {
        $this->assertSame('token', ScryfallRelatedComponent::Token->value);
        $this->assertSame('meld_part', ScryfallRelatedComponent::MeldPart->value);
        $this->assertSame('meld_result', ScryfallRelatedComponent::MeldResult->value);
        $this->assertSame('combo_piece', ScryfallRelatedComponent::ComboPiece->value);
    }

    #[Test]
    public function try_from_returns_null_for_unknown_component(): void
    {
        $this->assertNull(ScryfallRelatedComponent::tryFrom(''));
        $this->assertNull(ScryfallRelatedComponent::tryFrom('judges_corner'));
    }

    #[Test]
    public function cases_count_matches_expectation(): void
    {
        // Update this assertion when Scryfall adds a new component;
        // forces a conscious change to the import skip-counter logic.
        $this->assertCount(4, ScryfallRelatedComponent::cases());
    }
}
