<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Http\Controllers\Decks\DecksController;
use App\Models\CardStack;
use App\Models\Container;
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
 * Feature coverage for the BulkClaim endpoints exposed by
 * {@see DecksController}.
 *
 *  - GET /decks/{deck}/bulk-claim renders the page for the owner of a
 *    mode-C deck.
 *  - POST /decks/{deck}/bulk-claim with assignments persists pivot rows
 *    without touching state (state independence per PR 1).
 *  - POST /decks/{deck}/bulk-claim with an empty body is a no-op.
 *  - BulkClaim is gated to mode C — owners of mode-A / mode-B decks are
 *    rejected by {@see BulkClaimRequest::authorize()}.
 *  - PATCH /decks/{deck}/state flips the state directly (free flip).
 *  - All endpoints reject non-owners.
 */
class DeckBulkClaimControllerTest extends TestCase
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

    /**
     * BulkClaim is gated to mode C, so the default test deck is pinned
     * there. Tests that need a different mode (or no claim plumbing at
     * all) update the column explicitly.
     */
    private function makeDeck(User $user): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'BulkClaim Test Deck',
            'format' => CardFormat::Legacy->value,
            'state' => DeckState::Planned->value,
            'collection_mode' => 'C',
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
    public function bulk_claim_page_renders_for_owner_in_mode_c(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $this->makeDeckCard($deck, $oracle, $default);
        $this->makeCardStack($user, $default);

        $response = $this->actingAs($user)->get("/decks/{$deck->id}/bulk-claim");

        $response->assertOk();
    }

    #[Test]
    public function bulk_claim_page_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner);

        $response = $this->actingAs($other)->get("/decks/{$deck->id}/bulk-claim");

        $response->assertForbidden();
    }

    #[Test]
    public function bulk_claim_page_rejects_non_mode_c_decks(): void
    {
        // BulkClaim is gated to mode C — modes A and B are inadmissible.
        // Owners of mode-A / mode-B decks must switch via the deck-header
        // collection-tracking badge before the page becomes reachable.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $deck->update(['collection_mode' => 'A']);

        $this->actingAs($user)->get("/decks/{$deck->id}/bulk-claim")->assertForbidden();

        $deck->update(['collection_mode' => 'B']);
        $this->actingAs($user)->get("/decks/{$deck->id}/bulk-claim")->assertForbidden();
    }

    #[Test]
    public function store_bulk_claim_with_assignments_persists_pivot_and_leaves_state_alone(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);

        $response = $this->actingAs($user)->post(
            "/decks/{$deck->id}/bulk-claim",
            [
                'assignments' => [
                    $deckCard->id => [$stack->id],
                ],
                'container_id' => null,
            ],
        );

        $response->assertRedirect("/decks/{$deck->id}/unclaimed");
        // State independence: BulkClaim does not transition state.
        $this->assertSame('planned', $deck->fresh()->state->value);
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stack->id,
        ]);
    }

    #[Test]
    public function store_bulk_claim_handles_mixed_buy_new_and_assignment_rows(): void
    {
        // End-to-end: a deck with two rows. Row A gets a real assignment,
        // row B gets buy_new=true (no assignment, no existing stack).
        // Expected: row A pivots to its assigned stack; row B pivots to a
        // freshly minted stack of `quantity` copies.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracleA = $this->makeOracleCard('Card A');
        $defaultA = $this->makeDefaultCard($oracleA);
        $oracleB = $this->makeOracleCard('Card B');
        $defaultB = $this->makeDefaultCard($oracleB);
        $deckCardA = $this->makeDeckCard($deck, $oracleA, $defaultA, quantity: 1);
        $deckCardB = $this->makeDeckCard($deck, $oracleB, $defaultB, quantity: 2);
        $existingA = $this->makeCardStack($user, $defaultA, amount: 1);

        $response = $this->actingAs($user)->post(
            "/decks/{$deck->id}/bulk-claim",
            [
                'assignments' => [$deckCardA->id => [$existingA->id]],
                'buy_new' => [$deckCardB->id => true],
                'container_id' => null,
            ],
        );

        $response->assertRedirect("/decks/{$deck->id}/unclaimed");
        $this->assertSame('C', $deck->fresh()->collection_mode);

        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCardA->id,
            'card_stack_id' => $existingA->id,
        ]);
        $newStackB = CardStack::query()
            ->where('user_id', $user->id)
            ->where('default_card_id', $defaultB->id)
            ->first();
        $this->assertNotNull($newStackB);
        $this->assertSame(2, $newStackB->amount);
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCardB->id,
            'card_stack_id' => $newStackB->id,
        ]);
    }

    #[Test]
    public function store_bulk_claim_with_empty_body_is_a_noop(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $this->makeCardStack($user, $default);

        $response = $this->actingAs($user)->post("/decks/{$deck->id}/bulk-claim");

        $response->assertRedirect("/decks/{$deck->id}/unclaimed");
        // No pivot written, no state change.
        $this->assertSame('planned', $deck->fresh()->state->value);
        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
        ]);
    }

    #[Test]
    public function store_bulk_claim_swaps_deck_card_printing_when_alt_printing_picked(): void
    {
        // §2 path: user owns a stack of a different printing of the same
        // oracle card. Picking that stack should swap the deck_card's
        // default_card_id before claiming, so the badge reads
        // `claimed_for_this_deck` rather than `wrong_printing`.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard('Brainstorm');
        $primaryPrinting = $this->makeDefaultCard($oracle);
        $altPrinting = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $primaryPrinting);
        $altStack = $this->makeCardStack($user, $altPrinting);

        $this->actingAs($user)->post(
            "/decks/{$deck->id}/bulk-claim",
            [
                'assignments' => [$deckCard->id => [$altStack->id]],
                'container_id' => null,
            ],
        );

        $deckCard->refresh();
        $this->assertSame($altPrinting->id, $deckCard->default_card_id, 'Deck card printing was swapped to the picked stack.');
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $altStack->id,
        ]);
    }

    #[Test]
    public function bulk_claim_page_excludes_stacks_in_other_decks_deckboxes(): void
    {
        // Cross-deck poaching guard: stacks in *another* deck's deckbox
        // container are physically allocated to that deck — they must
        // not surface in this deck's BulkClaim dropdowns.
        $user = User::factory()->create();
        $thisDeck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $otherDeckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Other deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $otherDeck->update(['container_id' => $otherDeckbox->id]);

        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $this->makeDeckCard($thisDeck, $oracle, $default);
        // Stack lives in the *other* deck's deckbox — should be filtered out.
        $this->makeCardStack($user, $default, $otherDeckbox);

        $response = $this->actingAs($user)->get("/decks/{$thisDeck->id}/bulk-claim");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('cards.0.section', 'missing')
            ->where('cards.0.exact_stacks', [])
            ->where('cards.0.alt_stacks', [])
        );
    }

    #[Test]
    public function set_state_endpoint_flips_state_for_owner(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);

        $response = $this->actingAs($user)->patch(
            "/decks/{$deck->id}/state",
            ['state' => 'built'],
        );

        $response->assertRedirect("/decks/{$deck->id}");
        $this->assertSame('built', $deck->fresh()->state->value);
    }

    #[Test]
    public function set_state_endpoint_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner);

        $response = $this->actingAs($other)->patch(
            "/decks/{$deck->id}/state",
            ['state' => 'built'],
        );

        $response->assertForbidden();
        $this->assertSame('planned', $deck->fresh()->state->value);
    }

    #[Test]
    public function set_state_endpoint_free_flips_between_every_pair_of_states(): void
    {
        // Any state can transition to any other via the dedicated PATCH
        // endpoint — no order constraint. Walk every non-current target
        // from every starting state to lock in the free-flip behavior.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);

        $transitions = [
            ['planned', 'built'],
            ['built', 'archived'],
            ['archived', 'planned'],
            ['planned', 'archived'],
            ['archived', 'built'],
            ['built', 'planned'],
        ];
        foreach ($transitions as [$from, $to]) {
            $deck->update(['state' => $from]);

            $response = $this->actingAs($user)->patch(
                "/decks/{$deck->id}/state",
                ['state' => $to],
            );

            $response->assertRedirect("/decks/{$deck->id}");
            $this->assertSame($to, $deck->fresh()->state->value, "Expected {$from} → {$to}");
        }
    }

    #[Test]
    public function deck_show_emits_mode_b_implicit_status_per_card(): void
    {
        // Mode B decks expose `collectionMode: 'B'` plus per-card
        // `collection_implicit_status` counts. The mode-C
        // `collection_status` field stays null on mode-B decks — the two
        // render paths are mutually exclusive.
        $user = User::factory()->create();
        $deckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $deck = $this->makeDeck($user);
        $deck->update(['container_id' => $deckbox->id, 'collection_mode' => 'B']);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $this->makeCardStack($user, $default, $deckbox);
        $this->makeDeckCard($deck->fresh(), $oracle, $default, quantity: 1);

        $response = $this->actingAs($user)->get("/decks/{$deck->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('collectionMode', 'B')
            ->where('cards.0.collection_status', null)
            ->where('cards.0.collection_implicit_status.in_deckbox', 1)
            ->where('cards.0.collection_implicit_status.elsewhere', 0)
            ->where('cards.0.collection_implicit_status.missing', 0)
        );
    }

    #[Test]
    public function deck_show_keeps_mode_b_silent_when_deck_has_no_container(): void
    {
        // The per-card "in this deckbox / elsewhere" partition has no
        // anchor without `decks.container_id`, so the controller ships
        // a null `collection_implicit_status` per card. Under explicit
        // modes the badge still reports the user's chosen mode (B) —
        // we no longer downgrade to A on missing container.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $deck->update(['collection_mode' => 'B']);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $this->makeCardStack($user, $default);
        $this->makeDeckCard($deck->fresh(), $oracle, $default);

        $response = $this->actingAs($user)->get("/decks/{$deck->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('collectionMode', 'B')
            ->where('collectionBadgeMode', 'B')
            ->where('cards.0.collection_status', null)
            ->where('cards.0.collection_implicit_status', null)
        );
    }

    #[Test]
    public function deck_show_keeps_badge_mode_in_sync_with_real_mode_when_anchor_exists(): void
    {
        // Under explicit modes `collectionBadgeMode === collectionMode`
        // for every deck — the controller no longer demotes the badge
        // on a missing container.
        $user = User::factory()->create();
        $deckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $deck = $this->makeDeck($user);
        $deck->update(['container_id' => $deckbox->id, 'collection_mode' => 'B']);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $this->makeCardStack($user, $default, $deckbox);
        $this->makeDeckCard($deck->fresh(), $oracle, $default);

        $response = $this->actingAs($user)->get("/decks/{$deck->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('collectionMode', 'B')
            ->where('collectionBadgeMode', 'B')
        );
    }

    #[Test]
    public function store_bulk_claim_persists_optional_container_id(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $container = Container::create([
            'user_id' => $user->id,
            'name' => 'My Deck Box',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($user)->post(
            "/decks/{$deck->id}/bulk-claim",
            [
                'assignments' => [],
                'container_id' => $container->id,
            ],
        );

        $response->assertRedirect("/decks/{$deck->id}/unclaimed");
        $this->assertSame($container->id, $deck->fresh()->container_id);
    }
}
