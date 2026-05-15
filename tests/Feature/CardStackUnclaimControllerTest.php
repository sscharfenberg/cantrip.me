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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2.7 — feature coverage for the collection-side unclaim
 * endpoint at `DELETE /collection/cardstack/{cardStack}/claims`.
 *
 *  - Owner can DELETE; the pivot rows are removed; flash message lands.
 *  - Non-owners receive 403; pivot rows survive.
 *  - Multi-claim case: every pivot row is removed in one shot.
 *  - The deck's `collection_mode` is preserved on the affected
 *    deck — clearing the pin is a separate action (deck-header modal).
 *  - Redirect target follows the `?from=` query parameter.
 */
class CardStackUnclaimControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hard-skip on real MariaDB connections. RefreshDatabase would
     * `migrate:fresh` on staging and wipe live data — see
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
    public function owner_can_unclaim_a_stack(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);

        $response = $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims");

        // Default `from` lands the user back on the stack edit page —
        // exactly where the lifecycle 422 from a container move
        // attempt would have sent them. Closes the loop.
        $response->assertRedirect("/collection/cardstack/{$stack->id}/edit");
        $response->assertSessionHas('message');
        $response->assertSessionHas('type', 'success');
        $this->assertDatabaseMissing('deck_card_card_stack', [
            'card_stack_id' => $stack->id,
        ]);
    }

    #[Test]
    public function non_owner_receives_403(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($owner);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($owner, $default);
        $deckCard->cardStacks()->attach($stack->id);

        $response = $this->actingAs($other)
            ->delete("/collection/cardstack/{$stack->id}/claims");

        $response->assertForbidden();
        // The pivot row survives the rejected request.
        $this->assertDatabaseHas('deck_card_card_stack', [
            'card_stack_id' => $stack->id,
            'deck_card_id' => $deckCard->id,
        ]);
    }

    #[Test]
    public function multi_claim_stack_unclaims_atomically(): void
    {
        // Rare partial-coverage case: a single stack pivoted to
        // multiple deck_cards across two decks. One DELETE removes
        // every pivot row at once.
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

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims")
            ->assertRedirect("/collection/cardstack/{$stack->id}/edit");

        $this->assertDatabaseMissing('deck_card_card_stack', [
            'card_stack_id' => $stack->id,
        ]);
    }

    #[Test]
    public function affected_deck_keeps_its_collection_mode(): void
    {
        // Per the design: unclaiming from the collection side does
        // *not* change `decks.collection_mode`. The user's explicit
        // mode choice survives even when the unclaim removes the
        // deck's only claim. Mode transitions are owned by the
        // collection-mode badge's per-deck setter.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);
        $deck->update(['collection_mode' => 'C']);

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims");

        $this->assertSame('C', $deck->fresh()->collection_mode);
    }

    #[Test]
    public function from_query_redirects_to_the_originating_surface(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        // Stack lives in a container so the `?from=container`
        // redirect can target the container show route. The
        // `?from=collection` branch redirects to the collection root
        // unconditionally.
        $stack = $this->makeCardStack($user, $default);
        $deckCard->cardStacks()->attach($stack->id);

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims?from=collection")
            ->assertRedirect('/collection');
    }
}
