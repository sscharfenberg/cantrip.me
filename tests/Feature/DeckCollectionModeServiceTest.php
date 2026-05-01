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
use App\Services\DeckCollectionModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see DeckCollectionModeService}.
 *
 *  - promoteToExplicit pins `collection_mode = 'C'` and is a no-op when
 *    already pinned (avoids spurious updates).
 *  - clearAssignments detaches every pivot row attached to this deck's
 *    deck_cards and nulls the sticky pin atomically.
 *  - clearAssignments leaves pivots on *other* decks alone.
 *  - clearAssignments is a no-op for a deck without pivots and no pin.
 */
class DeckCollectionModeServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hard-skip on real MariaDB connections. See
     * {@see DeckCardCardStackPivotTest::setUp} for the rationale.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }
    }

    private function makeOracleCard(string $name = 'Test Card'): OracleCard
    {
        return OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => 'R',
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
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

    private function makeDeckCard(Deck $deck, OracleCard $oracle, DefaultCard $default, int $quantity = 1): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $default->id,
            'zone' => DeckZone::Main->value,
            'quantity' => $quantity,
        ]);
    }

    private function makeCardStack(User $user, DefaultCard $default, int $amount = 1): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $default->id,
            'amount' => $amount,
            'finish' => 1,
            'language' => 'en',
        ]);
    }

    #[Test]
    public function promote_to_explicit_pins_collection_mode_to_c(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $this->assertNull($deck->collection_mode);

        DeckCollectionModeService::promoteToExplicit($deck);

        $this->assertSame('C', $deck->fresh()->collection_mode);
    }

    #[Test]
    public function promote_to_explicit_is_a_noop_when_already_pinned(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $deck->update(['collection_mode' => 'C']);

        $beforeUpdatedAt = $deck->fresh()->updated_at;
        sleep(1);

        DeckCollectionModeService::promoteToExplicit($deck->fresh());

        $this->assertSame('C', $deck->fresh()->collection_mode);
        // No write happened — `updated_at` should be untouched.
        $this->assertEquals($beforeUpdatedAt->toIso8601String(), $deck->fresh()->updated_at->toIso8601String());
    }

    #[Test]
    public function clear_assignments_detaches_pivots_and_nulls_the_pin(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);
        $deck->update(['collection_mode' => 'C']);

        DeckCollectionModeService::clearAssignments($deck->fresh());

        $this->assertNull($deck->fresh()->collection_mode);
        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stack->id,
        ]);
        // The stack itself stays in the collection.
        $this->assertNotNull($stack->fresh());
    }

    #[Test]
    public function clear_assignments_leaves_pivots_on_other_decks_alone(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $otherDeckCard = $this->makeDeckCard($otherDeck, $oracle, $default);
        $stackA = $this->makeCardStack($user, $default);
        $stackB = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stackA->id);
        $otherDeckCard->cardStacks()->attach($stackB->id);
        $deck->update(['collection_mode' => 'C']);
        $otherDeck->update(['collection_mode' => 'C']);

        DeckCollectionModeService::clearAssignments($deck->fresh());

        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
        ]);
        // Pivot on the other deck must survive.
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $otherDeckCard->id,
            'card_stack_id' => $stackB->id,
        ]);
        $this->assertSame('C', $otherDeck->fresh()->collection_mode);
    }

    #[Test]
    public function clear_assignments_is_a_noop_for_a_clean_deck(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);

        $beforeUpdatedAt = $deck->fresh()->updated_at;
        sleep(1);

        DeckCollectionModeService::clearAssignments($deck->fresh());

        $this->assertNull($deck->fresh()->collection_mode);
        // No write should have happened.
        $this->assertEquals($beforeUpdatedAt->toIso8601String(), $deck->fresh()->updated_at->toIso8601String());
    }
}
