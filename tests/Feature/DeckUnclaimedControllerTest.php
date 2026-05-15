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
 * Feature coverage for the UnclaimedCardStacks endpoints.
 *
 *  - GET /decks/{deck}/unclaimed renders the page for modes B and C
 *    (owner-only, mode A rejected).
 *  - The Inertia payload lists every uncovered deck slot per mode.
 *  - POST /decks/{deck}/unclaimed/buy mints + claims a stack for each
 *    ticked row (mode C only).
 *  - GET /decks/{deck}/unclaimed/export streams a CSV.
 *  - Non-owners + mode-A owners are rejected from every endpoint.
 */
class DeckUnclaimedControllerTest extends TestCase
{
    use RefreshDatabase;

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
            'collector_number' => '42',
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    private function makeDeck(User $user, string $mode = 'C'): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'Unclaimed Test Deck',
            'format' => CardFormat::Legacy->value,
            'state' => DeckState::Planned->value,
            'collection_mode' => $mode,
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

    // ── GET /decks/{deck}/unclaimed ─────────────────────────────────────────

    #[Test]
    public function unclaimed_page_renders_for_mode_c_owner(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user, 'C');
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $this->makeDeckCard($deck, $oracle, $default, quantity: 1);

        $response = $this->actingAs($user)->get("/decks/{$deck->id}/unclaimed");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('mode', 'C')
            ->where('cards.0.unclaimed', 1)
        );
    }

    #[Test]
    public function unclaimed_page_renders_for_mode_b_owner(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user, 'B');
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $this->makeDeckCard($deck, $oracle, $default, quantity: 3);

        $response = $this->actingAs($user)->get("/decks/{$deck->id}/unclaimed");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('mode', 'B')
            ->where('cards.0.unclaimed', 3)
        );
    }

    #[Test]
    public function unclaimed_page_rejects_mode_a(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user, 'A');

        $this->actingAs($user)->get("/decks/{$deck->id}/unclaimed")->assertForbidden();
    }

    #[Test]
    public function unclaimed_page_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner, 'C');

        $this->actingAs($other)->get("/decks/{$deck->id}/unclaimed")->assertForbidden();
    }

    #[Test]
    public function mode_b_counts_in_deckbox_coverage(): void
    {
        // Deck wants 4, container has 3 of the printing → 1 unclaimed.
        $user = User::factory()->create();
        $deckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $deck = $this->makeDeck($user, 'B');
        $deck->update(['container_id' => $deckbox->id]);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $this->makeDeckCard($deck->fresh(), $oracle, $default, quantity: 4);
        $this->makeCardStack($user, $default, $deckbox, amount: 3);

        $response = $this->actingAs($user)->get("/decks/{$deck->id}/unclaimed");

        $response->assertInertia(fn ($page) => $page
            ->where('cards.0.unclaimed', 1)
        );
    }

    #[Test]
    public function mode_c_counts_pivot_coverage(): void
    {
        // Deck wants 2, one stack of amount 1 is claimed → 1 unclaimed.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user, 'C');
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 2);
        $stack = $this->makeCardStack($user, $default, amount: 1);
        $deckCard->cardStacks()->attach($stack->id);

        $response = $this->actingAs($user)->get("/decks/{$deck->id}/unclaimed");

        $response->assertInertia(fn ($page) => $page
            ->where('cards.0.unclaimed', 1)
        );
    }

    #[Test]
    public function fully_covered_rows_are_omitted(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user, 'C');
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 1);
        $stack = $this->makeCardStack($user, $default, amount: 1);
        $deckCard->cardStacks()->attach($stack->id);

        $response = $this->actingAs($user)->get("/decks/{$deck->id}/unclaimed");

        $response->assertInertia(fn ($page) => $page->where('cards', []));
    }

    // ── POST /decks/{deck}/unclaimed/buy ────────────────────────────────────

    #[Test]
    public function buy_endpoint_mints_stack_and_claims_for_each_ticked_row(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user, 'C');
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default, quantity: 2);

        $response = $this->actingAs($user)->post("/decks/{$deck->id}/unclaimed/buy", [
            'bought' => [$deckCard->id],
        ]);

        $response->assertRedirect("/decks/{$deck->id}/unclaimed");

        $newStack = CardStack::query()
            ->where('user_id', $user->id)
            ->where('default_card_id', $default->id)
            ->first();
        $this->assertNotNull($newStack);
        $this->assertSame(2, $newStack->amount);
        $this->assertDatabaseHas('deck_card_card_stack', [
            'deck_card_id' => $deckCard->id,
            'card_stack_id' => $newStack->id,
        ]);
    }

    #[Test]
    public function buy_endpoint_lands_new_stack_in_deck_container(): void
    {
        $user = User::factory()->create();
        $deckbox = Container::create([
            'user_id' => $user->id,
            'name' => 'Deckbox',
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
        $deck = $this->makeDeck($user, 'C');
        $deck->update(['container_id' => $deckbox->id]);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck->fresh(), $oracle, $default, quantity: 1);

        $this->actingAs($user)->post("/decks/{$deck->id}/unclaimed/buy", [
            'bought' => [$deckCard->id],
        ]);

        $newStack = CardStack::query()
            ->where('user_id', $user->id)
            ->where('default_card_id', $default->id)
            ->first();
        $this->assertSame($deckbox->id, $newStack->container_id);
    }

    #[Test]
    public function buy_endpoint_rejects_mode_b(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user, 'B');
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);

        $this->actingAs($user)
            ->post("/decks/{$deck->id}/unclaimed/buy", ['bought' => [$deckCard->id]])
            ->assertForbidden();
    }

    #[Test]
    public function buy_endpoint_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner, 'C');
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);

        $this->actingAs($other)
            ->post("/decks/{$deck->id}/unclaimed/buy", ['bought' => [$deckCard->id]])
            ->assertForbidden();
    }

    // ── GET /decks/{deck}/unclaimed/export ──────────────────────────────────

    #[Test]
    public function export_endpoint_streams_csv_for_owner(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user, 'C');
        $oracle = $this->makeOracleCard('Lightning Bolt');
        $default = $this->makeDefaultCard($oracle);
        $this->makeDeckCard($deck, $oracle, $default, quantity: 4);

        $response = $this->actingAs($user)->get("/decks/{$deck->id}/unclaimed/export");

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        $this->assertStringContainsString('name,edition,collector_number,qty,scryfall_id,zone,role', $body);
        $this->assertStringContainsString('Lightning Bolt', $body);
        $this->assertStringContainsString(',4,', $body);
    }

    #[Test]
    public function export_endpoint_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner, 'C');

        $this->actingAs($other)->get("/decks/{$deck->id}/unclaimed/export")->assertForbidden();
    }
}
