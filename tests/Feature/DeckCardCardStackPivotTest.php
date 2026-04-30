<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\ContainerType;
use App\Enums\DeckZone;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\CardStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schema + lifecycle tests for the deck_card_card_stack pivot.
 *
 * Covers:
 *  - Pivot relations resolve in both directions (DeckCard → CardStack, CardStack → DeckCard).
 *  - Cascade FK behaviour when the parent rows (deck, deck_card, stack) are deleted.
 *  - {@see CardStackService::splitStack} arithmetic and attribute inheritance.
 *  - {@see CardStack::isClaimed} accessor reflects pivot membership.
 *
 * Runs against the default in-memory SQLite via {@see RefreshDatabase}; no
 * Scryfall data needed because the tests stub minimal oracle/default card
 * rows inline.
 */
class DeckCardCardStackPivotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hard-skip on real MariaDB connections. {@see RefreshDatabase} runs
     * `migrate:fresh` on the first test, which would drop every table on
     * staging — including Scryfall data, real users, and existing decks.
     * These tests only need a working schema, which the in-memory SQLite
     * default provides; running them on staging is never the intent.
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

    private function makeCardStack(User $user, DefaultCard $default, ?Container $container = null, int $amount = 1): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $default->id,
            'container_id' => $container?->id,
            'amount' => $amount,
            'finish' => 1,
            'language' => 'en',
        ]);
    }

    private function makeContainer(User $user, ContainerType $type = ContainerType::Deckbox): Container
    {
        return Container::create([
            'user_id' => $user->id,
            'name' => 'Test Container',
            'type' => $type->value,
            'sort_order' => 1,
        ]);
    }

    #[Test]
    public function deck_card_can_attach_card_stacks_and_relation_resolves_both_ways(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deck = $this->makeDeck($user);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 2);
        $stack = $this->makeCardStack($user, $default, amount: 2);

        $deckCard->cardStacks()->attach($stack->id);

        $this->assertTrue($deckCard->cardStacks()->where('card_stacks.id', $stack->id)->exists());
        $this->assertTrue($stack->deckCards()->where('deck_cards.id', $deckCard->id)->exists());
    }

    #[Test]
    public function is_claimed_reflects_pivot_membership(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deck = $this->makeDeck($user);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);

        $this->assertFalse($stack->isClaimed());

        $deckCard->cardStacks()->attach($stack->id);

        $this->assertTrue($stack->fresh()->isClaimed());
    }

    #[Test]
    public function deleting_the_deck_cascades_to_deck_cards_and_pivot_rows(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deck = $this->makeDeck($user);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);

        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stack->id,
        ]);

        $deck->delete();

        $this->assertDatabaseMissing('deck_cards', ['id' => $deckCard->id]);
        $this->assertDatabaseMissing('deck_card_card_stack', ['deck_card_id' => $deckCard->id]);
        // The stack itself stays — it lives in the user's collection independent of the deck.
        $this->assertDatabaseHas('card_stacks', ['id' => $stack->id]);
    }

    #[Test]
    public function deleting_a_card_stack_cascades_pivot_rows(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deck = $this->makeDeck($user);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);

        $stack->delete();

        $this->assertDatabaseMissing('deck_card_card_stack', ['card_stack_id' => $stack->id]);
        // The deck_card itself stays — it's a slot, the physical claim is what got removed.
        $this->assertDatabaseHas('deck_cards', ['id' => $deckCard->id]);
    }

    #[Test]
    public function container_move_is_blocked_when_stack_is_claimed(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $sourceContainer = $this->makeContainer($user);
        $targetContainer = $this->makeContainer($user);
        $deck = $this->makeDeck($user);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default, $sourceContainer);
        $deckCard->cardStacks()->attach($stack->id);

        $response = $this->actingAs($user)->patch(
            route('cardstack.update', $stack->id),
            [
                'amount' => $stack->amount,
                'language' => $stack->language->value,
                'finish' => $stack->finish->label(),
                'container_id' => $targetContainer->id,
            ],
        );

        $response->assertSessionHasErrors('container_id');
        $this->assertSame($sourceContainer->id, $stack->fresh()->container_id);
    }

    #[Test]
    public function container_move_succeeds_once_stack_is_unclaimed(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $sourceContainer = $this->makeContainer($user);
        $targetContainer = $this->makeContainer($user);
        $deck = $this->makeDeck($user);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default, $sourceContainer);
        $deckCard->cardStacks()->attach($stack->id);
        $deckCard->cardStacks()->detach($stack->id);

        $response = $this->actingAs($user)->patch(
            route('cardstack.update', $stack->id),
            [
                'amount' => $stack->amount,
                'language' => $stack->language->value,
                'finish' => $stack->finish->label(),
                'container_id' => $targetContainer->id,
            ],
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame($targetContainer->id, $stack->fresh()->container_id);
    }

    #[Test]
    public function split_stack_decrements_source_and_returns_new_stack_with_amount(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $container = $this->makeContainer($user);
        $stack = $this->makeCardStack($user, $default, $container, amount: 4);

        $newStack = CardStackService::splitStack($stack, 1);

        $this->assertSame(3, $stack->fresh()->amount);
        $this->assertSame(1, $newStack->amount);
        $this->assertSame($stack->user_id, $newStack->user_id);
        $this->assertSame($stack->default_card_id, $newStack->default_card_id);
        $this->assertSame($stack->container_id, $newStack->container_id);
        $this->assertNotSame($stack->id, $newStack->id);
    }
}
