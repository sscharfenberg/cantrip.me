<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DeckCategory;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature coverage for the DELETE /api/decks/{deck}/categories/{deckCategory} endpoint.
 *
 *  - Deletes the category for the owner and nulls `category_id` on its cards.
 *  - Owner-only — non-owners receive 403 and the category survives.
 *  - A category belonging to another deck cannot be deleted via this deck (403).
 */
class DeckCategoryDestroyTest extends TestCase
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

    private function makeOracleCard(): OracleCard
    {
        return OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Card',
            'searchable_name' => 'test card',
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => 'R',
            'scryfall_uri' => 'https://example.com/test',
        ]);
    }

    private function makeDefaultCard(OracleCard $oracle): DefaultCard
    {
        $set = Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'set_type' => 'expansion',
            'scryfall_uri' => 'https://example.com/set',
            'path' => 'tst',
        ]);

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $oracle->name,
            'searchable_name' => $oracle->searchable_name,
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    private function makeDeckCard(Deck $deck, DeckCategory $category): DeckCard
    {
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);

        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $default->id,
            'zone' => DeckZone::Main->value,
            'quantity' => 1,
            'category_id' => $category->id,
        ]);
    }

    #[Test]
    public function owner_can_delete_a_category_and_cards_revert_to_no_category(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $category = $this->makeCategory($deck);
        $deckCard = $this->makeDeckCard($deck, $category);

        $response = $this->actingAs($user)
            ->deleteJson("/api/decks/{$deck->id}/categories/{$category->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('deck_categories', ['id' => $category->id]);
        $this->assertNull($deckCard->fresh()->category_id);
    }

    #[Test]
    public function delete_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner);
        $category = $this->makeCategory($deck);

        $response = $this->actingAs($other)
            ->deleteJson("/api/decks/{$deck->id}/categories/{$category->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('deck_categories', ['id' => $category->id]);
    }

    #[Test]
    public function delete_rejects_category_from_another_deck(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $category = $this->makeCategory($otherDeck);

        $response = $this->actingAs($user)
            ->deleteJson("/api/decks/{$deck->id}/categories/{$category->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('deck_categories', ['id' => $category->id]);
    }
}
