<?php

namespace Tests\Unit\Companions;

use App\Companions\CompanionRegistry;
use App\Models\OracleCard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CompanionRegistryTest extends TestCase
{
    #[Test]
    public function is_companion_matches_the_ten_by_exact_name(): void
    {
        foreach (CompanionRegistry::NAMES as $name) {
            $card = new OracleCard;
            $card->name = $name;

            $this->assertTrue(
                CompanionRegistry::isCompanion($card),
                "Expected '{$name}' to be recognised as a companion.",
            );
        }
    }

    #[Test]
    public function is_companion_rejects_unrelated_cards(): void
    {
        $card = new OracleCard;
        $card->name = 'Sol Ring';

        $this->assertFalse(CompanionRegistry::isCompanion($card));
    }

    #[Test]
    public function names_list_contains_exactly_ten_entries(): void
    {
        $this->assertCount(10, CompanionRegistry::NAMES);
    }
}
