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
 * Feature coverage for the planned→built finalize endpoints exposed by
 * {@see DecksController}.
 *
 *  - GET /decks/{deck}/finalize renders the wizard for the owner.
 *  - POST /decks/{deck}/finalize with assignments persists pivot rows.
 *  - POST /decks/{deck}/finalize with empty body still flips the state.
 *  - PATCH /decks/{deck}/state flips the state directly (mode-A path).
 *  - Both endpoints reject non-owners.
 */
class DeckFinalizeControllerTest extends TestCase
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
            'name' => 'Finalize Test Deck',
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
    public function finalize_page_renders_for_owner(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $this->makeDeckCard($deck, $oracle, $default);
        $this->makeCardStack($user, $default);

        $response = $this->actingAs($user)->get("/decks/{$deck->id}/finalize");

        $response->assertOk();
    }

    #[Test]
    public function finalize_page_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner);

        $response = $this->actingAs($other)->get("/decks/{$deck->id}/finalize");

        $response->assertForbidden();
    }

    #[Test]
    public function store_finalize_with_assignments_persists_pivot_and_redirects(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);

        $response = $this->actingAs($user)->post(
            "/decks/{$deck->id}/finalize",
            [
                'assignments' => [
                    $deckCard->id => [$stack->id],
                ],
                'container_id' => null,
            ],
        );

        $response->assertRedirect("/decks/{$deck->id}");
        $this->assertSame('built', $deck->fresh()->state->value);
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $stack->id,
        ]);
    }

    #[Test]
    public function store_finalize_handles_mixed_buy_new_and_assignment_rows(): void
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
            "/decks/{$deck->id}/finalize",
            [
                'assignments' => [$deckCardA->id => [$existingA->id]],
                'buy_new' => [$deckCardB->id => true],
                'container_id' => null,
            ],
        );

        $response->assertRedirect("/decks/{$deck->id}");
        $this->assertSame('built', $deck->fresh()->state->value);
        $this->assertSame('C', $deck->fresh()->collection_mode);

        // Row A pivots to the existing stack, row B pivots to a new stack of 2.
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
    public function store_finalize_with_empty_body_skips_to_built(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $this->makeCardStack($user, $default);

        $response = $this->actingAs($user)->post("/decks/{$deck->id}/finalize");

        $response->assertRedirect("/decks/{$deck->id}");
        $this->assertSame('built', $deck->fresh()->state->value);
        $this->assertDatabaseMissing('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
        ]);
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
    public function store_finalize_persists_optional_container_id(): void
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
            "/decks/{$deck->id}/finalize",
            [
                'assignments' => [],
                'container_id' => $container->id,
            ],
        );

        $response->assertRedirect("/decks/{$deck->id}");
        $this->assertSame($container->id, $deck->fresh()->container_id);
    }
}
