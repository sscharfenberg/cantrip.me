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
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The deck page's "Unclaim" on a card: `claimed_stacks` on each card row
 * tells the menu when to offer it, and
 * `DELETE /api/decks/{deck}/cards/{deckCard}/claims` frees the stacks —
 * optionally moving them and taking the card out of the deck.
 */
class DeckCardUnclaimTest extends TestCase
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

    #[Test]
    public function the_deck_page_names_each_cards_claimed_stacks_to_its_owner(): void
    {
        [$user, $deck, $deckCard, $stack, $deckbox] = $this->claimedCard();

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.0.id', $deckCard->id)
                ->where('cards.0.claimed_stacks', [['id' => $stack->id, 'container_id' => $deckbox->id]]));
    }

    #[Test]
    public function nothing_is_claimed_while_collection_integration_is_off(): void
    {
        [$user, $deck] = $this->claimedCard();
        $user->update(['collection_integration_enabled' => false]);

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cards.0.claimed_stacks', []));
    }

    #[Test]
    public function unclaiming_frees_the_stack_and_leaves_it_where_it_is(): void
    {
        [$user, $deck, $deckCard, $stack, $deckbox] = $this->claimedCard();

        $this->actingAs($user)
            ->deleteJson($this->url($deck, $deckCard))
            ->assertOk()
            ->assertExactJson(['removed' => false]);

        $this->assertFalse($stack->fresh()->isClaimed());
        $this->assertSame($deckbox->id, $stack->fresh()->container_id);
        $this->assertNotNull($deckCard->fresh());
    }

    #[Test]
    public function only_this_cards_claim_goes(): void
    {
        [$user, $deck, $deckCard, $stack] = $this->claimedCard();
        $otherDeck = $this->deck($user, 'Other');
        $otherCard = DeckCard::create([
            'deck_id' => $otherDeck->id,
            'oracle_card_id' => $deckCard->oracle_card_id,
            'default_card_id' => $deckCard->default_card_id,
            'zone' => DeckZone::Main->value,
            'quantity' => 1,
        ]);
        $otherCard->cardStacks()->attach($stack->id);

        $this->actingAs($user)->deleteJson($this->url($deck, $deckCard))->assertOk();

        $this->assertSame([$otherCard->id], $stack->fresh()->deckCards()->pluck('deck_cards.id')->all());
    }

    #[Test]
    public function the_freed_stack_can_move_to_another_container_or_to_unsorted(): void
    {
        [$user, $deck, $deckCard, $stack] = $this->claimedCard();
        $binder = $this->container($user, 'Binder');

        $this->actingAs($user)->deleteJson($this->url($deck, $deckCard), ['move_to' => $binder->id])->assertOk();
        $this->assertSame($binder->id, $stack->fresh()->container_id);

        $deckCard->cardStacks()->attach($stack->id);
        $this->actingAs($user)->deleteJson($this->url($deck, $deckCard), ['move_to' => 'unsorted'])->assertOk();
        $this->assertNull($stack->fresh()->container_id);
    }

    #[Test]
    public function the_card_can_leave_the_deck_too(): void
    {
        [$user, $deck, $deckCard, $stack] = $this->claimedCard();

        $this->actingAs($user)
            ->deleteJson($this->url($deck, $deckCard), ['remove_from_deck' => true])
            ->assertOk()
            ->assertExactJson(['removed' => true]);

        $this->assertNull($deckCard->fresh());
        $this->assertNotNull($stack->fresh());
        $this->assertFalse($stack->fresh()->isClaimed());
    }

    #[Test]
    public function another_users_container_is_refused_and_nothing_changes(): void
    {
        [$user, $deck, $deckCard, $stack, $deckbox] = $this->claimedCard();
        $foreign = $this->container(User::factory()->create(), 'Not yours');

        $this->actingAs($user)
            ->deleteJson($this->url($deck, $deckCard), ['move_to' => $foreign->id, 'remove_from_deck' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('move_to');

        $this->assertTrue($stack->fresh()->isClaimed());
        $this->assertSame($deckbox->id, $stack->fresh()->container_id);
        $this->assertNotNull($deckCard->fresh());
    }

    #[Test]
    public function only_the_owner_unclaims_and_only_a_card_of_that_deck(): void
    {
        [$user, $deck, $deckCard, $stack] = $this->claimedCard();
        $otherDeck = $this->deck($user, 'Other');

        $this->actingAs(User::factory()->create())->deleteJson($this->url($deck, $deckCard))->assertForbidden();
        $this->actingAs($user)->deleteJson($this->url($otherDeck, $deckCard))->assertForbidden();

        $this->assertTrue($stack->fresh()->isClaimed());
    }

    private function url(Deck $deck, DeckCard $deckCard): string
    {
        return "/api/decks/{$deck->id}/cards/{$deckCard->id}/claims";
    }

    /**
     * A finished mode-C deck of a new user, with one card claimed by a
     * stack in the deck's deckbox.
     *
     * @return array{0: User, 1: Deck, 2: DeckCard, 3: CardStack, 4: Container}
     */
    private function claimedCard(): array
    {
        $user = User::factory()->create(['collection_integration_enabled' => true]);
        $deck = $this->deck($user, 'Burn');
        $deckbox = $this->container($user, 'Deckbox');
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => 'Lightning Bolt',
            'searchable_name' => 'lightning bolt',
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => 'R',
            'scryfall_uri' => 'https://example.com/bolt',
        ]);
        $set = Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Alpha',
            'code' => Str::lower(Str::random(3)),
            'released_at' => '1993-08-05',
            'card_count' => 1,
            'set_type' => 'core',
            'scryfall_uri' => 'https://example.com/set',
            'path' => 'tst',
        ]);
        $printing = DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => 'Lightning Bolt',
            'searchable_name' => 'lightning bolt',
            'collector_number' => '161',
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
        $deckCard = DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $printing->id,
            'zone' => DeckZone::Main->value,
            'quantity' => 1,
        ]);
        $stack = CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $printing->id,
            'container_id' => $deckbox->id,
            'amount' => 1,
            'finish' => 1,
            'language' => 'en',
        ]);
        $deckCard->cardStacks()->attach($stack->id);

        return [$user, $deck, $deckCard, $stack, $deckbox];
    }

    private function deck(User $user, string $name): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => $name,
            'format' => CardFormat::Legacy->value,
            'state' => DeckState::Built->value,
            'collection_mode' => 'C',
        ]);
    }

    private function container(User $user, string $name): Container
    {
        return Container::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
    }
}
