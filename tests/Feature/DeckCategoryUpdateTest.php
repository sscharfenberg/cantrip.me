<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckState;
use App\Models\Deck;
use App\Models\DeckCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature coverage for the PATCH /api/decks/{deck}/categories/{deckCategory} endpoint.
 *
 *  - Renames a category for the owner and returns the new name as JSON.
 *  - Rejects an empty / over-long name with a 422.
 *  - Owner-only — non-owners receive 403 and the name is untouched.
 *  - A category belonging to another deck cannot be renamed via this deck (403).
 */
class DeckCategoryUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }
    }

    private function makeDeck(User $user): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'Test Deck',
            'format' => CardFormat::Legacy->value,
            'state' => DeckState::Built->value,
        ]);
    }

    private function makeCategory(Deck $deck, string $name = 'Ramp'): DeckCategory
    {
        return DeckCategory::create([
            'deck_id' => $deck->id,
            'name' => $name,
        ]);
    }

    #[Test]
    public function owner_can_rename_a_category(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $category = $this->makeCategory($deck);

        $response = $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/categories/{$category->id}", ['name' => 'Card Advantage']);

        $response->assertOk();
        $response->assertJson(['name' => 'Card Advantage']);
        $this->assertSame('Card Advantage', $category->fresh()->name);
    }

    #[Test]
    public function rename_rejects_empty_name(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $category = $this->makeCategory($deck);

        $response = $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/categories/{$category->id}", ['name' => '']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
        $this->assertSame('Ramp', $category->fresh()->name);
    }

    #[Test]
    public function rename_rejects_over_long_name(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $category = $this->makeCategory($deck);

        $response = $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/categories/{$category->id}", [
                'name' => str_repeat('a', DeckCategory::NAME_MAX + 1),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
        $this->assertSame('Ramp', $category->fresh()->name);
    }

    #[Test]
    public function rename_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner);
        $category = $this->makeCategory($deck);

        $response = $this->actingAs($other)
            ->patchJson("/api/decks/{$deck->id}/categories/{$category->id}", ['name' => 'Hijacked']);

        $response->assertForbidden();
        $this->assertSame('Ramp', $category->fresh()->name);
    }

    #[Test]
    public function rename_rejects_category_from_another_deck(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $category = $this->makeCategory($otherDeck);

        $response = $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/categories/{$category->id}", ['name' => 'Wrong Deck']);

        $response->assertForbidden();
        $this->assertSame('Ramp', $category->fresh()->name);
    }
}
