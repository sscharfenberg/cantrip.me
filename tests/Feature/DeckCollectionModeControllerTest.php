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
 * Feature coverage for the PATCH /decks/{deck}/collection-mode endpoint.
 *
 *  - Persists the requested mode (A / B / C).
 *  - Transitioning C → B/A cascade-deletes every pivot row attached to
 *    this deck.
 *  - Owner-only — non-owners receive 403 and the deck is untouched.
 *  - Rejects unknown mode values with a 422.
 *  - Redirects to the deck show page with a success flash on success.
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
    public function set_endpoint_persists_each_valid_mode_for_owner(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);

        foreach (['B', 'C', 'A'] as $mode) {
            $response = $this->actingAs($user)->patch("/decks/{$deck->id}/collection-mode", ['mode' => $mode]);

            $response->assertRedirect("/decks/{$deck->id}");
            $response->assertSessionHas('message');
            $response->assertSessionHas('type', 'success');
            $this->assertSame($mode, $deck->fresh()->collection_mode);
        }
    }

    #[Test]
    public function set_endpoint_cascade_deletes_pivot_rows_on_c_to_b_transition(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);
        $deck->update(['collection_mode' => 'C']);

        $response = $this->actingAs($user)->patch("/decks/{$deck->id}/collection-mode", ['mode' => 'B']);

        $response->assertRedirect("/decks/{$deck->id}");
        $this->assertSame('B', $deck->fresh()->collection_mode);
        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
        ]);
    }

    #[Test]
    public function set_endpoint_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner);

        $response = $this->actingAs($other)->patch("/decks/{$deck->id}/collection-mode", ['mode' => 'C']);

        $response->assertForbidden();
        $this->assertSame('A', $deck->fresh()->collection_mode);
    }

    #[Test]
    public function set_endpoint_rejects_unknown_mode_value(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);

        $response = $this->actingAs($user)
            ->from("/decks/{$deck->id}")
            ->patch("/decks/{$deck->id}/collection-mode", ['mode' => 'Z']);

        $response->assertSessionHasErrors('mode');
        $this->assertSame('A', $deck->fresh()->collection_mode);
    }
}
