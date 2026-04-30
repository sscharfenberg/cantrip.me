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
use App\Services\DeckCardAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see DeckCardAssignmentService}.
 *
 *  - First assignment: pivot row gets attached.
 *  - Replace: existing pivots are cleared before the new one is attached.
 *  - Clear (null stack): all pivots removed, no new attach.
 *  - Oversized stack: {@see CardStackService::splitStack} runs and the
 *    split-off stack is the one attached (source stack is decremented).
 */
class DeckCardAssignmentServiceTest extends TestCase
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
    public function attaches_a_stack_when_the_deck_card_has_no_existing_pivot(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 1);
        $stack = $this->makeCardStack($user, $default, amount: 1);

        DeckCardAssignmentService::replaceAssignedStack($deckCard, $stack);

        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stack->id,
        ]);
        $this->assertSame(1, $deckCard->cardStacks()->count());
    }

    #[Test]
    public function replaces_an_existing_assignment(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 1);
        $oldStack = $this->makeCardStack($user, $default, amount: 1);
        $newStack = $this->makeCardStack($user, $default, amount: 1);
        $deckCard->cardStacks()->attach($oldStack->id);

        DeckCardAssignmentService::replaceAssignedStack($deckCard, $newStack);

        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $oldStack->id,
        ]);
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $newStack->id,
        ]);
        $this->assertSame(1, $deckCard->cardStacks()->count());
    }

    #[Test]
    public function clears_the_assignment_when_stack_is_null(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 1);
        $stack = $this->makeCardStack($user, $default, amount: 1);
        $deckCard->cardStacks()->attach($stack->id);

        DeckCardAssignmentService::replaceAssignedStack($deckCard, null);

        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
        ]);
    }

    #[Test]
    public function splits_an_oversized_stack_before_attaching(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        // Deck wants 1×.
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 1);
        // User has a stack of 4 — only 1 should be claimed.
        $stack = $this->makeCardStack($user, $default, amount: 4);

        DeckCardAssignmentService::replaceAssignedStack($deckCard, $stack);

        // Source stack now holds 3, a new 1-stack carries the pivot.
        $this->assertSame(3, $stack->fresh()->amount);

        $stacks = CardStack::query()
            ->where('user_id', $user->id)
            ->where('default_card_id', $default->id)
            ->get();
        $this->assertCount(2, $stacks);

        $claimedStack = $stacks->firstWhere('id', '!=', $stack->id);
        $this->assertNotNull($claimedStack);
        $this->assertSame(1, $claimedStack->amount);

        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $claimedStack->id,
        ]);
        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stack->id,
        ]);
    }

    #[Test]
    public function attaches_an_exact_size_stack_without_splitting(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 4);
        $stack = $this->makeCardStack($user, $default, amount: 4);

        DeckCardAssignmentService::replaceAssignedStack($deckCard, $stack);

        // Stack is unchanged — no split happened.
        $this->assertSame(4, $stack->fresh()->amount);
        $this->assertSame(1, CardStack::query()->where('user_id', $user->id)->count());

        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stack->id,
        ]);
    }
}
