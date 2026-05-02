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
use App\Services\CardStackClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2.5 — coverage for {@see CardStackClaimService::bulkClaimsForStacks}.
 *
 *  - Stacks with no claims are absent from the result.
 *  - A single claim returns one entry per stack.
 *  - Multi-deck claims surface every deck (rare but legal).
 *  - Same-deck multi-pivot collapses to one entry per (stack, deck).
 *  - One bounded query for the whole batch (no N+1).
 */
class CardStackClaimServiceTest extends TestCase
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

    private function makeDeck(User $user, string $name = 'Test Deck'): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => $name,
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
    public function returns_empty_for_unclaimed_stacks(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $stack = $this->makeCardStack($user, $default);

        $result = CardStackClaimService::bulkClaimsForStacks([$stack->id]);

        // Unclaimed stack id is absent from the map (callers default to []).
        $this->assertSame([], $result);
    }

    #[Test]
    public function returns_empty_when_called_with_no_stack_ids(): void
    {
        $this->assertSame([], CardStackClaimService::bulkClaimsForStacks([]));
    }

    #[Test]
    public function returns_one_entry_for_a_single_claim(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user, 'Mono-Red Burn');
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);

        $result = CardStackClaimService::bulkClaimsForStacks([$stack->id]);

        $this->assertArrayHasKey($stack->id, $result);
        $this->assertSame([
            ['deck_id' => $deck->id, 'deck_name' => 'Mono-Red Burn'],
        ], $result[$stack->id]);
    }

    #[Test]
    public function deduplicates_same_deck_multi_pivot_into_one_entry(): void
    {
        // Edge case: a single stack pivoted to TWO deck_cards in the
        // SAME deck (e.g. partial-coverage split). UX assumes one badge
        // per deck — the service should collapse this to a single entry.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user, 'Split Deck');
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCardA = $this->makeDeckCard($deck, $oracle, $default);
        $deckCardB = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCardA->cardStacks()->attach($stack->id);
        $deckCardB->cardStacks()->attach($stack->id);

        $result = CardStackClaimService::bulkClaimsForStacks([$stack->id]);

        $this->assertCount(1, $result[$stack->id]);
        $this->assertSame($deck->id, $result[$stack->id][0]['deck_id']);
    }

    #[Test]
    public function returns_one_entry_per_distinct_deck(): void
    {
        $user = User::factory()->create();
        $deckA = $this->makeDeck($user, 'Deck A');
        $deckB = $this->makeDeck($user, 'Deck B');
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCardA = $this->makeDeckCard($deckA, $oracle, $default);
        $deckCardB = $this->makeDeckCard($deckB, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCardA->cardStacks()->attach($stack->id);
        $deckCardB->cardStacks()->attach($stack->id);

        $result = CardStackClaimService::bulkClaimsForStacks([$stack->id]);

        $this->assertCount(2, $result[$stack->id]);
        $deckIds = array_column($result[$stack->id], 'deck_id');
        $this->assertContains($deckA->id, $deckIds);
        $this->assertContains($deckB->id, $deckIds);
    }

    #[Test]
    public function unclaim_all_removes_every_pivot_for_the_given_stack(): void
    {
        // Phase 2.7. Multi-claim case: a single stack pivoted to two
        // distinct decks. `unclaimAll` should detach both pivots in a
        // single batched DELETE.
        $user = User::factory()->create();
        $deckA = $this->makeDeck($user, 'Deck A');
        $deckB = $this->makeDeck($user, 'Deck B');
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCardA = $this->makeDeckCard($deckA, $oracle, $default);
        $deckCardB = $this->makeDeckCard($deckB, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCardA->cardStacks()->attach($stack->id);
        $deckCardB->cardStacks()->attach($stack->id);

        $count = CardStackClaimService::unclaimAll($stack);

        $this->assertSame(2, $count);
        $this->assertSame([], CardStackClaimService::bulkClaimsForStacks([$stack->id]));
    }

    #[Test]
    public function unclaim_all_leaves_other_stacks_untouched(): void
    {
        // Phase 2.7. Targeting one stack must not collateral-damage any
        // pivot row pointing at a sibling stack of the same printing.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, 2);
        $stackA = $this->makeCardStack($user, $default);
        $stackB = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach([$stackA->id, $stackB->id]);

        $count = CardStackClaimService::unclaimAll($stackA);

        $this->assertSame(1, $count);
        // Stack A is gone from the claims map; stack B's claim survives.
        $claims = CardStackClaimService::bulkClaimsForStacks([$stackA->id, $stackB->id]);
        $this->assertArrayNotHasKey($stackA->id, $claims);
        $this->assertArrayHasKey($stackB->id, $claims);
        $this->assertCount(1, $claims[$stackB->id]);
    }

    #[Test]
    public function unclaim_all_is_a_noop_when_the_stack_has_no_claims(): void
    {
        // Phase 2.7. Calling on an unclaimed stack returns 0 and
        // doesn't error — controller can flash a "0 decks" message
        // safely (in practice the UI gates the button to claimed
        // stacks, so the count is always >= 1, but defence in depth).
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $stack = $this->makeCardStack($user, $default);

        $count = CardStackClaimService::unclaimAll($stack);

        $this->assertSame(0, $count);
    }

    #[Test]
    public function uses_a_single_query_for_a_batched_lookup(): void
    {
        // Smoke against N+1 — looking up claims for 10 stacks should
        // still hit the DB exactly once, regardless of how many of
        // them are claimed.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $stackIds = [];
        for ($i = 0; $i < 10; $i++) {
            $oracle = $this->makeOracleCard("Card {$i}");
            $default = $this->makeDefaultCard($oracle);
            $deckCard = $this->makeDeckCard($deck, $oracle, $default);
            $stack = $this->makeCardStack($user, $default);
            if ($i % 2 === 0) {
                $deckCard->cardStacks()->attach($stack->id);
            }
            $stackIds[] = $stack->id;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        CardStackClaimService::bulkClaimsForStacks($stackIds);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }
}
