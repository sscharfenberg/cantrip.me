<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\DeckFinalizeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see DeckFinalizeService}.
 *
 *  - Plain finalize creates pivot rows + transitions state to Built.
 *  - Auto-splits a deck_card row when partial coverage is offered.
 *  - Auto-splits a card_stack when its `amount` exceeds the deck_card's quantity.
 *  - Skip path just transitions state.
 */
class DeckFinalizeServiceTest extends TestCase
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
            'state' => DeckState::Planned->value,
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

    #[Test]
    public function persist_assignments_attaches_pivot_rows_and_sets_state_to_built(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 1);
        $stack = $this->makeCardStack($user, $default, amount: 1);

        DeckFinalizeService::persistAssignments(
            $deck,
            [$deckCard->id => [$stack->id]],
            [],
            null,
        );

        $this->assertSame('built', $deck->fresh()->state->value);
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stack->id,
        ]);
    }

    #[Test]
    public function persist_assignments_splits_deck_card_on_partial_coverage(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        // Deck wants 4× of this printing.
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 4);
        // User has 3× — so one is missing.
        $stack = $this->makeCardStack($user, $default, amount: 3);

        DeckFinalizeService::persistAssignments(
            $deck,
            [$deckCard->id => [$stack->id]],
            [],
            null,
        );

        $rows = DeckCard::query()
            ->where('deck_id', $deck->id)
            ->where('oracle_card_id', $oracle->id)
            ->orderBy('quantity', 'desc')
            ->get();

        $this->assertCount(2, $rows, 'Deck card should be split into claimed + leftover rows.');
        $this->assertSame(3, $rows[0]->quantity);
        $this->assertSame(1, $rows[1]->quantity);

        // Pivot row should attach to the claimed row, not the leftover one.
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $rows[0]->id,
            'card_stack_id' => $stack->id,
        ]);
        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $rows[1]->id,
        ]);
    }

    #[Test]
    public function persist_assignments_splits_oversized_card_stack(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        // Deck wants 1×.
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 1);
        // User has a stack of 4 — only 1 should be claimed.
        $stack = $this->makeCardStack($user, $default, amount: 4);

        DeckFinalizeService::persistAssignments(
            $deck,
            [$deckCard->id => [$stack->id]],
            [],
            null,
        );

        // Original stack now holds 3, and a new 1-stack carries the pivot.
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
    }

    #[Test]
    public function persist_assignments_with_container_id_sets_deck_container(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $container = Container::create([
            'user_id' => $user->id,
            'name' => 'Test Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);

        DeckFinalizeService::persistAssignments($deck, [], [], $container->id);

        $this->assertSame($container->id, $deck->fresh()->container_id);
        $this->assertSame('built', $deck->fresh()->state->value);
    }

    #[Test]
    public function persist_assignments_pins_collection_mode_to_c_on_claim(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);

        $this->assertNull($deck->collection_mode);

        DeckFinalizeService::persistAssignments(
            $deck,
            [$deckCard->id => [$stack->id]],
            [],
            null,
        );

        $this->assertSame('C', $deck->fresh()->collection_mode);
    }

    #[Test]
    public function persist_assignments_does_not_pin_collection_mode_when_no_stacks_were_claimed(): void
    {
        // Skip path: empty assignments (or assignments that all resolve
        // to no-op because the stacks don't match) should leave the deck
        // in whatever mode it was — no implicit promotion to C.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);

        DeckFinalizeService::persistAssignments($deck, [], [], null);

        $this->assertNull($deck->fresh()->collection_mode);
        $this->assertSame('built', $deck->fresh()->state->value);
    }

    #[Test]
    public function transition_to_built_changes_state_without_pivot_writes(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $this->makeCardStack($user, $default);

        DeckFinalizeService::transitionToBuilt($deck);

        $this->assertSame('built', $deck->fresh()->state->value);
        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
        ]);
    }

    // ── buy_new (Phase 2.4) ────────────────────────────────────────────────

    #[Test]
    public function buy_new_creates_a_full_quantity_stack_when_user_has_none(): void
    {
        // User owns no copies of this printing — checking "bought new"
        // mints a fresh stack of `quantity` and attaches it to the
        // deck_card. The deck_card itself stays a single row.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 4);

        DeckFinalizeService::persistAssignments(
            $deck,
            [],
            [$deckCard->id => true],
            null,
        );

        // Single new stack of 4, attached via the pivot.
        $stacks = CardStack::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $stacks);
        $this->assertSame(4, $stacks->first()->amount);
        $this->assertNull($stacks->first()->container_id);
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stacks->first()->id,
        ]);
        // Deck_card row is not split — full coverage means no leftover.
        $this->assertSame(1, DeckCard::query()->where('deck_id', $deck->id)->count());
        // Deck pinned to mode C since at least one pivot was written.
        $this->assertSame('C', $deck->fresh()->collection_mode);
    }

    #[Test]
    public function buy_new_pads_the_uncovered_remainder_alongside_an_assignment(): void
    {
        // Deck wants 4×, user assigns a 3-stack and ticks "bought new"
        // for the remaining 1×. Existing stack lives in a binder; the
        // wizard's bottom container picker is left null — the bought
        // stack lands unsorted and so doesn't merge into the binder
        // stack (different container_id makes them distinct rows).
        // Expected: 2 separate stacks, both attached, no split.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $binder = Container::create([
            'user_id' => $user->id,
            'name' => 'Test Binder',
            'type' => 'binder',
            'sort_order' => 1,
        ]);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 4);
        $existing = $this->makeCardStack($user, $default, container: $binder, amount: 3);

        DeckFinalizeService::persistAssignments(
            $deck,
            [$deckCard->id => [$existing->id]],
            [$deckCard->id => true],
            null,
        );

        $stacks = CardStack::query()->where('user_id', $user->id)->get();
        $this->assertCount(2, $stacks);
        $newStack = $stacks->firstWhere('id', '!=', $existing->id);
        $this->assertNotNull($newStack);
        $this->assertSame(1, $newStack->amount);
        $this->assertNull($newStack->container_id);

        // Both stacks attached to the same deck_card row (no split).
        $this->assertSame(2, $deckCard->cardStacks()->count());
        $this->assertSame(1, DeckCard::query()->where('deck_id', $deck->id)->count());
    }

    #[Test]
    public function buy_new_is_a_noop_when_assignment_already_fully_covers_the_row(): void
    {
        // Deck wants 1×, user assigns a 1-stack AND ticks "bought new".
        // Uncovered = 0, so the buy-new branch should mint nothing.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 1);
        $existing = $this->makeCardStack($user, $default, amount: 1);

        DeckFinalizeService::persistAssignments(
            $deck,
            [$deckCard->id => [$existing->id]],
            [$deckCard->id => true],
            null,
        );

        // Still exactly one stack — no spurious creation.
        $this->assertSame(1, CardStack::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $existing->id,
        ]);
    }

    #[Test]
    public function buy_new_merges_into_an_existing_unsorted_stack_of_the_same_printing(): void
    {
        // Pre-seed an unsorted stack of the same printing/lang/finish.
        // Buy-new should increment that stack's amount instead of
        // creating a duplicate row, then attach the merged stack.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 2);
        // Pre-seed a 1-stack matching the buy-new defaults (en / nonfoil
        // / null condition / null container) — should merge.
        $existing = $this->makeCardStack($user, $default, amount: 1);

        DeckFinalizeService::persistAssignments(
            $deck,
            [],
            [$deckCard->id => true],
            null,
        );

        // Still one stack, now with amount = 3 (1 pre-seed + 2 minted).
        $this->assertSame(1, CardStack::query()->where('user_id', $user->id)->count());
        $this->assertSame(3, $existing->fresh()->amount);
        // The merged stack is attached.
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $existing->id,
        ]);
    }

    #[Test]
    public function buy_new_drops_the_minted_stack_into_the_chosen_container(): void
    {
        // When the wizard's bottom dropdown picks a deckbox, bought-new
        // stacks should land there too — same anchor as the deck.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $deckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Test Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 1);

        DeckFinalizeService::persistAssignments(
            $deck,
            [],
            [$deckCard->id => true],
            $deckbox->id,
        );

        $stack = CardStack::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($stack);
        $this->assertSame($deckbox->id, $stack->container_id);
        // The deck's container is also set on this path (existing behaviour).
        $this->assertSame($deckbox->id, $deck->fresh()->container_id);
    }
}
