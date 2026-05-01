<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
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
 * Feature coverage for the collection-mode promote / clear endpoints.
 *
 *  - PATCH `/decks/{deck}/collection-mode/promote` flips a mode-B deck
 *    to mode C with no claims; owner-only.
 *  - DELETE `/decks/{deck}/collection-mode/assignments` detaches every
 *    pivot row and nulls the sticky pin; owner-only.
 *  - Both endpoints redirect to the deck show page with a flash message.
 */
class DeckCollectionModeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }
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

    private function makeDeck(User $user): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'Test Deck',
            'format' => CardFormat::Legacy->value,
            'state' => DeckState::Built->value,
        ]);
    }

    private function makeDeckCard(Deck $deck, OracleCard $oracle, DefaultCard $default): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $default->id,
            'zone' => DeckZone::Main->value,
            'quantity' => 1,
        ]);
    }

    private function makeCardStack(User $user, DefaultCard $default): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $default->id,
            'amount' => 1,
            'finish' => 1,
            'language' => 'en',
        ]);
    }

    #[Test]
    public function promote_endpoint_pins_collection_mode_to_c_for_owner(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);

        $response = $this->actingAs($user)->patch("/decks/{$deck->id}/collection-mode/promote");

        $response->assertRedirect("/decks/{$deck->id}");
        $response->assertSessionHas('message');
        $response->assertSessionHas('type', 'success');
        $this->assertSame('C', $deck->fresh()->collection_mode);
    }

    #[Test]
    public function promote_endpoint_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner);

        $response = $this->actingAs($other)->patch("/decks/{$deck->id}/collection-mode/promote");

        $response->assertForbidden();
        $this->assertNull($deck->fresh()->collection_mode);
    }

    #[Test]
    public function clear_endpoint_detaches_pivots_and_nulls_pin_for_owner(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);
        $deck->update(['collection_mode' => 'C']);

        $response = $this->actingAs($user)->delete("/decks/{$deck->id}/collection-mode/assignments");

        $response->assertRedirect("/decks/{$deck->id}");
        $response->assertSessionHas('message');
        $response->assertSessionHas('type', 'success');
        $this->assertNull($deck->fresh()->collection_mode);
        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
        ]);
    }

    #[Test]
    public function clear_endpoint_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($owner, $default);
        $deckCard->cardStacks()->attach($stack->id);
        $deck->update(['collection_mode' => 'C']);

        $response = $this->actingAs($other)->delete("/decks/{$deck->id}/collection-mode/assignments");

        $response->assertForbidden();
        $this->assertSame('C', $deck->fresh()->collection_mode);
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stack->id,
        ]);
    }
}
