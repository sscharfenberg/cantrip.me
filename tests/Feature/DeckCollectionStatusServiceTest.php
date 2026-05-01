<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckZone;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\DeckCollectionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see DeckCollectionStatusService}.
 *
 *  - effectiveMode returns A / B / C correctly given the data shape.
 *  - statusForDeck resolves each of the five status values.
 *  - statusForDeck issues a single batched join per call (perf smoke).
 */
class DeckCollectionStatusServiceTest extends TestCase
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

    private function makeOracleCard(string $name): OracleCard
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

    private function makeSet(): Set
    {
        return Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'set_type' => 'expansion',
            'scryfall_uri' => 'https://example.com/set',
            'path' => 'tst',
        ]);
    }

    private function makeDefaultCard(OracleCard $oracle, ?Set $set = null): DefaultCard
    {
        $set ??= $this->makeSet();

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $oracle->name,
            'searchable_name' => $oracle->searchable_name,
            'collector_number' => (string) random_int(1, 999),
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

    // ── effectiveMode ──────────────────────────────────────────────────────

    #[Test]
    public function mode_a_when_user_has_no_card_stacks(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);

        $this->assertSame('A', DeckCollectionStatusService::effectiveMode($user, $deck));
    }

    #[Test]
    public function mode_a_when_user_opted_out_via_master_switch(): void
    {
        $user = User::factory()->create(['collection_integration_enabled' => false]);
        $oracle = $this->makeOracleCard('Sol Ring');
        $default = $this->makeDefaultCard($oracle);
        $this->makeCardStack($user, $default);
        $deck = $this->makeDeck($user);

        $this->assertSame('A', DeckCollectionStatusService::effectiveMode($user, $deck));
    }

    #[Test]
    public function mode_b_when_user_has_stacks_but_deck_has_no_pivot_rows(): void
    {
        // Mode B is independent of `decks.container_id` so the
        // planned→built finalize wizard still fires for users who
        // haven't picked a container yet — the wizard is precisely
        // where they pick one. Per-card badge rendering is gated
        // separately at the controller layer (see
        // {@see DeckFinalizeControllerTest}).
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard('Sol Ring');
        $default = $this->makeDefaultCard($oracle);
        $this->makeCardStack($user, $default);
        $deck = $this->makeDeck($user);

        $this->assertSame('B', DeckCollectionStatusService::effectiveMode($user, $deck));
    }

    #[Test]
    public function mode_c_is_sticky_after_all_pivot_rows_are_cascade_deleted(): void
    {
        // Regression: deleting a claimed card stack from the collection
        // cascade-removes the pivot row. Before the fix, that left the
        // deck with zero pivot rows, dropping it from C back to B and
        // hiding every status badge — including the `not_owned` badge
        // the user was expecting on the now-orphaned deck card row.
        // The sticky `decks.collection_mode = 'C'` pin keeps the deck in
        // mode C so the badge layer keeps rendering.
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard('Sticky Card');
        $default = $this->makeDefaultCard($oracle);
        $stack = $this->makeCardStack($user, $default);
        // Unrelated stack so the user still owns a collection after the
        // claimed stack is deleted (otherwise mode A would short-circuit).
        $unrelatedOracle = $this->makeOracleCard('Filler');
        $unrelatedDefault = $this->makeDefaultCard($unrelatedOracle);
        $this->makeCardStack($user, $unrelatedDefault);

        $deck = $this->makeDeck($user);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $deckCard->cardStacks()->attach($stack->id);
        // Simulate the wizard pinning the mode after a claim.
        $deck->update(['collection_mode' => 'C']);

        $stack->delete(); // cascade removes the only pivot row.

        $this->assertSame('C', DeckCollectionStatusService::effectiveMode($user->fresh(), $deck->fresh()));
    }

    #[Test]
    public function mode_c_when_deck_has_at_least_one_pivot_row(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard('Sol Ring');
        $default = $this->makeDefaultCard($oracle);
        $stack = $this->makeCardStack($user, $default);
        $deck = $this->makeDeck($user);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $deckCard->cardStacks()->attach($stack->id);

        $this->assertSame('C', DeckCollectionStatusService::effectiveMode($user, $deck));
    }

    // ── statusForDeck ──────────────────────────────────────────────────────

    #[Test]
    public function status_for_deck_returns_each_of_the_five_states(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $set = $this->makeSet();

        // 1) claimed_for_this_deck
        $oracleA = $this->makeOracleCard('Card A');
        $defaultA = $this->makeDefaultCard($oracleA, $set);
        $stackA = $this->makeCardStack($user, $defaultA);
        $deckCardA = $this->makeDeckCard($deck, $oracleA, $defaultA);
        $deckCardA->cardStacks()->attach($stackA->id);

        // 2) available — owned, no pivot row at all
        $oracleB = $this->makeOracleCard('Card B');
        $defaultB = $this->makeDefaultCard($oracleB, $set);
        $this->makeCardStack($user, $defaultB);
        $deckCardB = $this->makeDeckCard($deck, $oracleB, $defaultB);

        // 3) claimed_by_other_deck
        $oracleC = $this->makeOracleCard('Card C');
        $defaultC = $this->makeDefaultCard($oracleC, $set);
        $stackC = $this->makeCardStack($user, $defaultC);
        $otherDeckCardC = $this->makeDeckCard($otherDeck, $oracleC, $defaultC);
        $otherDeckCardC->cardStacks()->attach($stackC->id);
        $deckCardC = $this->makeDeckCard($deck, $oracleC, $defaultC);

        // 4) wrong_printing — own a different printing of the same oracle card
        $oracleD = $this->makeOracleCard('Card D');
        $defaultD1 = $this->makeDefaultCard($oracleD, $set);
        $defaultD2 = $this->makeDefaultCard($oracleD, $set);
        $this->makeCardStack($user, $defaultD2);
        $deckCardD = $this->makeDeckCard($deck, $oracleD, $defaultD1);

        // 5) not_owned — no stacks of this oracle card at all
        $oracleE = $this->makeOracleCard('Card E');
        $defaultE = $this->makeDefaultCard($oracleE, $set);
        $deckCardE = $this->makeDeckCard($deck, $oracleE, $defaultE);

        $statuses = DeckCollectionStatusService::statusForDeck($deck);

        $this->assertSame('claimed_for_this_deck', $statuses[$deckCardA->id]);
        $this->assertSame('available', $statuses[$deckCardB->id]);
        $this->assertSame('claimed_by_other_deck', $statuses[$deckCardC->id]);
        $this->assertSame('wrong_printing', $statuses[$deckCardD->id]);
        $this->assertSame('not_owned', $statuses[$deckCardE->id]);
    }

    #[Test]
    public function partial_coverage_leftover_row_resolves_to_not_owned_not_claimed_for_this_deck(): void
    {
        // Regression: deck wants 4×, user owns 3× of same printing, wizard
        // splits the deck card into a claimed 3-row + leftover 1-row. The
        // leftover row must NOT inherit `claimed_for_this_deck` from its
        // sibling — the resolver previously matched on deck_id only, so any
        // sibling row in the same deck would push the leftover to a green
        // check. Fix scoped the match to the exact deck_card_id.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard('Erratic Portal');
        $default = $this->makeDefaultCard($oracle);
        $stack = $this->makeCardStack($user, $default, amount: 3);

        $claimedRow = $this->makeDeckCard($deck, $oracle, $default, quantity: 3);
        $leftoverRow = $this->makeDeckCard($deck, $oracle, $default, quantity: 1);
        $claimedRow->cardStacks()->attach($stack->id);

        $statuses = DeckCollectionStatusService::statusForDeck($deck);

        $this->assertSame('claimed_for_this_deck', $statuses[$claimedRow->id]);
        $this->assertSame('not_owned', $statuses[$leftoverRow->id]);
    }

    #[Test]
    public function partial_coverage_leftover_falls_back_to_wrong_printing_when_other_printings_exist(): void
    {
        // Same partial-coverage shape as above, plus a stack of a different
        // printing of the same oracle card. The leftover row should report
        // `wrong_printing` (a swap is actionable) rather than `not_owned`
        // (which would suggest the user has nothing).
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $set = $this->makeSet();
        $oracle = $this->makeOracleCard('Two-Print Card');
        $primary = $this->makeDefaultCard($oracle, $set);
        $alt = $this->makeDefaultCard($oracle, $set);
        $stack = $this->makeCardStack($user, $primary, amount: 3);
        $this->makeCardStack($user, $alt);

        $claimedRow = $this->makeDeckCard($deck, $oracle, $primary, quantity: 3);
        $leftoverRow = $this->makeDeckCard($deck, $oracle, $primary, quantity: 1);
        $claimedRow->cardStacks()->attach($stack->id);

        $statuses = DeckCollectionStatusService::statusForDeck($deck);

        $this->assertSame('claimed_for_this_deck', $statuses[$claimedRow->id]);
        $this->assertSame('wrong_printing', $statuses[$leftoverRow->id]);
    }

    // ── implicitStatusForDeck ─────────────────────────────────────────────

    #[Test]
    public function implicit_status_returns_in_deckbox_count_when_all_copies_sit_in_the_decks_container(): void
    {
        $user = User::factory()->create();
        $deckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $oracle = $this->makeOracleCard('Lightning Bolt');
        $default = $this->makeDefaultCard($oracle);
        $this->makeCardStack($user, $default, $deckbox, amount: 4);
        $deck = $this->makeDeck($user);
        $deck->update(['container_id' => $deckbox->id]);
        $deckCard = $this->makeDeckCard($deck->fresh(), $oracle, $default, quantity: 4);

        $statuses = DeckCollectionStatusService::implicitStatusForDeck($deck->fresh());

        $this->assertSame(
            ['in_deckbox' => 4, 'elsewhere' => 0, 'missing' => 0],
            $statuses[$deckCard->id]
        );
    }

    #[Test]
    public function implicit_status_partitions_stacks_by_container(): void
    {
        $user = User::factory()->create();
        $deckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $binder = Container::create([
            'user_id' => $user->id,
            'name' => 'Binder',
            'type' => 'binder',
            'sort_order' => 2,
        ]);
        $oracle = $this->makeOracleCard('Brainstorm');
        $default = $this->makeDefaultCard($oracle);
        $this->makeCardStack($user, $default, $deckbox, amount: 2);
        $this->makeCardStack($user, $default, $binder, amount: 1);
        $deck = $this->makeDeck($user);
        $deck->update(['container_id' => $deckbox->id]);
        $deckCard = $this->makeDeckCard($deck->fresh(), $oracle, $default, quantity: 4);

        $statuses = DeckCollectionStatusService::implicitStatusForDeck($deck->fresh());

        // Owned 3 (2 here + 1 elsewhere), needed 4 → missing 1.
        $this->assertSame(
            ['in_deckbox' => 2, 'elsewhere' => 1, 'missing' => 1],
            $statuses[$deckCard->id]
        );
    }

    #[Test]
    public function implicit_status_treats_unsorted_stacks_as_elsewhere(): void
    {
        // The deck has a `container_id`; stacks with `container_id = null`
        // (unsorted) belong in `elsewhere`, not `in_deckbox`.
        $user = User::factory()->create();
        $deckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $oracle = $this->makeOracleCard('Counterspell');
        $default = $this->makeDefaultCard($oracle);
        $this->makeCardStack($user, $default, container: null, amount: 2);
        $deck = $this->makeDeck($user);
        $deck->update(['container_id' => $deckbox->id]);
        $deckCard = $this->makeDeckCard($deck->fresh(), $oracle, $default, quantity: 2);

        $statuses = DeckCollectionStatusService::implicitStatusForDeck($deck->fresh());

        $this->assertSame(
            ['in_deckbox' => 0, 'elsewhere' => 2, 'missing' => 0],
            $statuses[$deckCard->id]
        );
    }

    #[Test]
    public function implicit_status_reports_missing_when_no_stacks_match_the_printing(): void
    {
        $user = User::factory()->create();
        $deckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $oracle = $this->makeOracleCard('Mana Drain');
        $default = $this->makeDefaultCard($oracle);
        $deck = $this->makeDeck($user);
        $deck->update(['container_id' => $deckbox->id]);
        $deckCard = $this->makeDeckCard($deck->fresh(), $oracle, $default, quantity: 1);

        $statuses = DeckCollectionStatusService::implicitStatusForDeck($deck->fresh());

        $this->assertSame(
            ['in_deckbox' => 0, 'elsewhere' => 0, 'missing' => 1],
            $statuses[$deckCard->id]
        );
    }

    #[Test]
    public function implicit_status_does_not_count_wrong_printing_copies(): void
    {
        // The user owns a different printing of the same oracle card.
        // Mode B is per-printing — the alt-printing stack must not
        // contribute to either `in_deckbox` or `elsewhere`. (Mode C's
        // wrong_printing status is the actionable hint for this case.)
        $user = User::factory()->create();
        $deckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $set = $this->makeSet();
        $oracle = $this->makeOracleCard('Brainstorm');
        $primary = $this->makeDefaultCard($oracle, $set);
        $alt = $this->makeDefaultCard($oracle, $set);
        $this->makeCardStack($user, $alt, $deckbox, amount: 3);
        $deck = $this->makeDeck($user);
        $deck->update(['container_id' => $deckbox->id]);
        $deckCard = $this->makeDeckCard($deck->fresh(), $oracle, $primary, quantity: 1);

        $statuses = DeckCollectionStatusService::implicitStatusForDeck($deck->fresh());

        $this->assertSame(
            ['in_deckbox' => 0, 'elsewhere' => 0, 'missing' => 1],
            $statuses[$deckCard->id]
        );
    }

    #[Test]
    public function implicit_status_counts_oversized_stacks_at_face_value(): void
    {
        // Deck wants 1× but the user has a 4-stack of the printing in
        // the deckbox. Counting at face value lets the tooltip phrase
        // "all 4 are in this deck's deckbox" — not "1 of 1 here, 3
        // somewhere unseen". Missing clamps to 0.
        $user = User::factory()->create();
        $deckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $oracle = $this->makeOracleCard('Sol Ring');
        $default = $this->makeDefaultCard($oracle);
        $this->makeCardStack($user, $default, $deckbox, amount: 4);
        $deck = $this->makeDeck($user);
        $deck->update(['container_id' => $deckbox->id]);
        $deckCard = $this->makeDeckCard($deck->fresh(), $oracle, $default, quantity: 1);

        $statuses = DeckCollectionStatusService::implicitStatusForDeck($deck->fresh());

        $this->assertSame(
            ['in_deckbox' => 4, 'elsewhere' => 0, 'missing' => 0],
            $statuses[$deckCard->id]
        );
    }

    #[Test]
    public function status_for_deck_uses_a_bounded_number_of_queries(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $set = $this->makeSet();

        // Seed 10 deck cards with mixed status to ensure scaling.
        for ($i = 0; $i < 10; $i++) {
            $oracle = $this->makeOracleCard("Card {$i}");
            $default = $this->makeDefaultCard($oracle, $set);
            $this->makeDeckCard($deck, $oracle, $default);
            if ($i % 2 === 0) {
                $this->makeCardStack($user, $default);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        DeckCollectionStatusService::statusForDeck($deck);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Two queries: deck_cards lookup + the joined stacks query.
        // Asserting <= 3 to allow connection-level chatter on some drivers.
        $this->assertLessThanOrEqual(3, count($queries));
    }
}
