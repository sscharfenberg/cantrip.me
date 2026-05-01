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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2.5 — feature coverage for the controller-side claim payload.
 *
 * Verifies the three list endpoints actually ship `claims` per row /
 * per response so the frontend badge has data to render:
 *  - GET `/collection`
 *  - GET `/containers/{container}`
 *  - GET `/collection/cardstack/{cardStack}/preview`
 */
class CollectionClaimsPayloadTest extends TestCase
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
            'name' => 'Burn Deck',
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

    private function makeCardStack(User $user, DefaultCard $default, ?Container $container = null): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $default->id,
            'container_id' => $container?->id,
            'amount' => 1,
            'finish' => 1,
            'language' => 'en',
        ]);
    }

    #[Test]
    public function collection_list_ships_claims_per_row(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);

        $response = $this->actingAs($user)->get('/collection');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('table.rows.0.id', $stack->id)
            ->where('table.rows.0.claims.0.deck_id', $deck->id)
            ->where('table.rows.0.claims.0.deck_name', 'Burn Deck')
        );
    }

    #[Test]
    public function collection_list_emits_empty_claims_for_unclaimed_rows(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $stack = $this->makeCardStack($user, $default);

        $response = $this->actingAs($user)->get('/collection');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('table.rows.0.id', $stack->id)
            ->where('table.rows.0.claims', [])
        );
    }

    #[Test]
    public function container_show_ships_claims_per_row(): void
    {
        $user = User::factory()->create();
        $container = Container::create([
            'user_id' => $user->id,
            'name' => 'Test Box',
            'type' => 'binder',
            'sort_order' => 1,
        ]);
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default, $container);
        $deckCard->cardStacks()->attach($stack->id);

        $response = $this->actingAs($user)->get("/containers/{$container->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('table.rows.0.id', $stack->id)
            ->where('table.rows.0.claims.0.deck_name', 'Burn Deck')
        );
    }

    #[Test]
    public function container_show_strips_claims_for_non_owner(): void
    {
        // Non-owners viewing a public container must not see claim
        // data — the deck names belong to the owner's private deck
        // list, and the deck-show pages would 404 for the visitor
        // anyway. The frontend hides the column too, but the
        // controller is the privacy boundary.
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $container = Container::create([
            'user_id' => $owner->id,
            'name' => 'Public Box',
            'type' => 'binder',
            'sort_order' => 1,
            'visibility' => 'public',
        ]);
        $deck = $this->makeDeck($owner);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($owner, $default, $container);
        $deckCard->cardStacks()->attach($stack->id);

        $response = $this->actingAs($other)->get("/containers/{$container->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('isOwner', false)
            ->where('table.rows.0.id', $stack->id)
            ->where('table.rows.0.claims', [])
        );
    }

    #[Test]
    public function preview_endpoint_ships_claims(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);

        $response = $this->actingAs($user)->get("/collection/cardstack/{$stack->id}/preview");

        $response->assertOk();
        $response->assertJsonPath('claims.0.deck_id', $deck->id);
        $response->assertJsonPath('claims.0.deck_name', 'Burn Deck');
    }
}
