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
 *  - setMode writes the requested column value (A / B / C).
 *  - setMode is a no-op when the deck is already in the requested mode.
 *  - Transitioning C → B or C → A cascade-deletes every pivot row
 *    attached to this deck's deck_cards.
 *  - Cascade-delete on C → B/A leaves pivots on other decks alone.
 *  - Non-C → non-C transitions don't touch the pivot table.
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
    public function set_mode_writes_the_requested_column_value(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $this->assertSame('A', $deck->collection_mode);

        DeckCollectionModeService::setMode($deck, 'B');
        $this->assertSame('B', $deck->fresh()->collection_mode);

        DeckCollectionModeService::setMode($deck->fresh(), 'C');
        $this->assertSame('C', $deck->fresh()->collection_mode);
    }

    #[Test]
    public function set_mode_is_a_noop_when_already_in_the_requested_mode(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $deck->update(['collection_mode' => 'C']);

        $beforeUpdatedAt = $deck->fresh()->updated_at;
        sleep(1);

        DeckCollectionModeService::setMode($deck->fresh(), 'C');

        $this->assertSame('C', $deck->fresh()->collection_mode);
        $this->assertEquals($beforeUpdatedAt->toIso8601String(), $deck->fresh()->updated_at->toIso8601String());
    }

    #[Test]
    public function transition_from_c_to_b_cascade_deletes_every_pivot_row(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);
        $deck->update(['collection_mode' => 'C']);

        DeckCollectionModeService::setMode($deck->fresh(), 'B');

        $this->assertSame('B', $deck->fresh()->collection_mode);
        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stack->id,
        ]);
        // The stack itself stays in the collection.
        $this->assertNotNull($stack->fresh());
    }

    #[Test]
    public function transition_from_c_to_a_cascade_deletes_every_pivot_row(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);
        $deck->update(['collection_mode' => 'C']);

        DeckCollectionModeService::setMode($deck->fresh(), 'A');

        $this->assertSame('A', $deck->fresh()->collection_mode);
        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
        ]);
    }

    #[Test]
    public function cascade_delete_leaves_pivots_on_other_decks_alone(): void
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

        DeckCollectionModeService::setMode($deck->fresh(), 'B');

        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
        ]);
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $otherDeckCard->id,
            'card_stack_id' => $stackB->id,
        ]);
        $this->assertSame('C', $otherDeck->fresh()->collection_mode);
    }

    #[Test]
    public function transition_from_a_to_b_does_not_touch_pivot_table(): void
    {
        // Sanity: only C → non-C cascade-deletes. Going from A or B to
        // any other mode is a pure column write.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);

        DeckCollectionModeService::setMode($deck->fresh(), 'B');

        $this->assertSame('B', $deck->fresh()->collection_mode);
    }

    #[Test]
    public function transition_from_b_to_c_does_not_delete_pivot_rows(): void
    {
        // Edge: mode B technically shouldn't have pivot rows under the
        // explicit-mode model, but if any exist (legacy data) the
        // B → C transition must leave them intact — only C → B/A
        // triggers cascade-delete.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);
        $deck->update(['collection_mode' => 'B']);

        DeckCollectionModeService::setMode($deck->fresh(), 'C');

        $this->assertSame('C', $deck->fresh()->collection_mode);
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stack->id,
        ]);
    }
}
