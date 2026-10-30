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
 * Taking copies out of a deck — "−" (`PATCH …/quantity`) and "Remove"
 * (`DELETE …/cards/{deckCard}`) — frees the claims the deck card no longer
 * needs: unclaimed copies go first, a stack bigger than what is freed
 * splits, and freed copies lying in the deck's deckbox need a place, which
 * the page is asked for with a 409.
 */
class DeckCardRemoveClaimedCopiesTest extends TestCase
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
    public function an_unclaimed_copy_leaves_first_and_the_claims_stay(): void
    {
        [$user, $deck, $deckCard, $stack] = $this->claimedCard(quantity: 5, claimed: 3);

        $this->actingAs($user)->patchJson($this->quantityUrl($deck, $deckCard), ['delta' => -2])
            ->assertOk()
            ->assertExactJson(['quantity' => 3]);

        $this->assertSame(3, $stack->fresh()->amount);
        $this->assertTrue($stack->fresh()->isClaimed());
        $this->assertSame(1, CardStack::query()->count());
    }

    #[Test]
    public function a_claimed_copy_splits_off_its_stack_and_stays_where_it_lies(): void
    {
        [$user, $deck, $deckCard, $stack, $binder] = $this->claimedCard(quantity: 5, claimed: 5);

        $this->actingAs($user)->patchJson($this->quantityUrl($deck, $deckCard), ['delta' => -1])
            ->assertOk()
            ->assertExactJson(['quantity' => 4]);

        $this->assertSame(4, $stack->fresh()->amount);
        $this->assertSame([$deckCard->id], $stack->fresh()->deckCards()->pluck('deck_cards.id')->all());
        $freed = CardStack::query()->where('id', '!=', $stack->id)->sole();
        $this->assertSame(1, $freed->amount);
        $this->assertFalse($freed->isClaimed());
        $this->assertSame($binder->id, $freed->container_id);
    }

    #[Test]
    public function copies_freed_from_the_deckbox_ask_where_to_go_and_nothing_changes(): void
    {
        [$user, $deck, $deckCard, $stack, $deckbox] = $this->claimedCard(quantity: 5, claimed: 5, inDeckbox: true);

        $this->actingAs($user)->patchJson($this->quantityUrl($deck, $deckCard), ['delta' => -2])
            ->assertStatus(409)
            ->assertExactJson(['reason' => 'freed_copies_need_a_place', 'count' => 2, 'container_id' => $deckbox->id]);

        $this->assertSame(5, $deckCard->fresh()->quantity);
        $this->assertSame(5, $stack->fresh()->amount);
        $this->assertSame(1, CardStack::query()->count());
    }

    #[Test]
    public function the_freed_copies_go_where_the_answer_says_and_join_their_twin_there(): void
    {
        [$user, $deck, $deckCard, $stack] = $this->claimedCard(quantity: 5, claimed: 5, inDeckbox: true);
        $binder = $this->container($user, 'Lands');
        $twin = $this->stack($user, $deckCard, $binder, 30);
        $foil = $this->stack($user, $deckCard, $binder, 2, ['finish' => 2]);

        $this->actingAs($user)
            ->patchJson($this->quantityUrl($deck, $deckCard), ['delta' => -2, 'move_to' => $binder->id])
            ->assertOk()
            ->assertExactJson(['quantity' => 3]);

        $this->assertSame(3, $stack->fresh()->amount);
        $this->assertSame(32, $twin->fresh()->amount);
        $this->assertSame(2, $foil->fresh()->amount);
        $this->assertSame(3, CardStack::query()->count());
    }

    #[Test]
    public function the_freed_copies_can_go_to_unsorted(): void
    {
        [$user, $deck, $deckCard, $stack] = $this->claimedCard(quantity: 5, claimed: 5, inDeckbox: true);

        $this->actingAs($user)
            ->patchJson($this->quantityUrl($deck, $deckCard), ['delta' => -1, 'move_to' => 'unsorted'])
            ->assertOk();

        $freed = CardStack::query()->where('id', '!=', $stack->id)->sole();
        $this->assertNull($freed->container_id);
        $this->assertFalse($freed->isClaimed());
    }

    #[Test]
    public function the_last_copy_frees_its_whole_stack(): void
    {
        [$user, $deck, $deckCard, $stack] = $this->claimedCard(quantity: 1, claimed: 1, inDeckbox: true);

        $this->actingAs($user)->patchJson($this->quantityUrl($deck, $deckCard), ['delta' => -1])->assertStatus(409);
        $this->actingAs($user)
            ->patchJson($this->quantityUrl($deck, $deckCard), ['delta' => -1, 'move_to' => 'unsorted'])
            ->assertOk()
            ->assertExactJson(['deleted' => true]);

        $this->assertNull($deckCard->fresh());
        $this->assertNull($stack->fresh()->container_id);
        $this->assertSame(1, $stack->fresh()->amount);
    }

    #[Test]
    public function removing_the_card_asks_first_then_moves_its_stacks(): void
    {
        [$user, $deck, $deckCard, $stack] = $this->claimedCard(quantity: 4, claimed: 4, inDeckbox: true);
        $binder = $this->container($user, 'Lands');

        $this->actingAs($user)->deleteJson($this->cardUrl($deck, $deckCard))
            ->assertStatus(409)
            ->assertJson(['count' => 4]);
        $this->assertNotNull($deckCard->fresh());

        $this->actingAs($user)->deleteJson($this->cardUrl($deck, $deckCard), ['move_to' => $binder->id])->assertNoContent();

        $this->assertNull($deckCard->fresh());
        $this->assertSame($binder->id, $stack->fresh()->container_id);
        $this->assertSame(4, $stack->fresh()->amount);
        $this->assertFalse($stack->fresh()->isClaimed());
    }

    #[Test]
    public function removing_a_card_claimed_outside_the_deckbox_does_not_ask(): void
    {
        [$user, $deck, $deckCard, $stack, $binder] = $this->claimedCard(quantity: 2, claimed: 2);
        $deck->update(['container_id' => $this->container($user, 'Deckbox')->id]);

        $this->actingAs($user)->deleteJson($this->cardUrl($deck, $deckCard))->assertNoContent();

        $this->assertFalse($stack->fresh()->isClaimed());
        $this->assertSame($binder->id, $stack->fresh()->container_id);
    }

    #[Test]
    public function with_the_master_switch_off_nothing_is_asked_and_the_copies_stay(): void
    {
        [$user, $deck, $deckCard, $stack, $deckbox] = $this->claimedCard(quantity: 3, claimed: 3, inDeckbox: true);
        $user->update(['collection_integration_enabled' => false]);

        $this->actingAs($user)->patchJson($this->quantityUrl($deck, $deckCard), ['delta' => -1])->assertOk();

        $this->assertSame(2, $stack->fresh()->amount);
        $freed = CardStack::query()->where('id', '!=', $stack->id)->sole();
        $this->assertFalse($freed->isClaimed());
        $this->assertSame($deckbox->id, $freed->container_id);
    }

    #[Test]
    public function the_newest_claim_is_freed_first(): void
    {
        [$user, $deck, $deckCard, $older, $binder] = $this->claimedCard(quantity: 3, claimed: 2);
        $newer = $this->stack($user, $deckCard, $binder, 1);
        $deckCard->cardStacks()->attach($newer->id, ['created_at' => now()->addMinute()]);

        $this->actingAs($user)->patchJson($this->quantityUrl($deck, $deckCard), ['delta' => -1])->assertOk();

        $this->assertTrue($older->fresh()->isClaimed());
        $this->assertSame(2, $older->fresh()->amount);
        $this->assertFalse($newer->fresh()->isClaimed());
    }

    #[Test]
    public function another_decks_claim_stays_on_the_split_off_copies_which_do_not_move(): void
    {
        [$user, $deck, $deckCard, $stack, $deckbox] = $this->claimedCard(quantity: 3, claimed: 3, inDeckbox: true);
        $otherCard = DeckCard::create([
            'deck_id' => $this->deck($user, 'Other')->id,
            'oracle_card_id' => $deckCard->oracle_card_id,
            'default_card_id' => $deckCard->default_card_id,
            'zone' => DeckZone::Main->value,
            'quantity' => 3,
        ]);
        $otherCard->cardStacks()->attach($stack->id);

        $this->actingAs($user)
            ->patchJson($this->quantityUrl($deck, $deckCard), ['delta' => -1, 'move_to' => 'unsorted'])
            ->assertOk();

        $freed = CardStack::query()->where('id', '!=', $stack->id)->sole();
        $this->assertSame([$otherCard->id], $freed->deckCards()->pluck('deck_cards.id')->all());
        $this->assertSame($deckbox->id, $freed->container_id);
        $this->assertSame(3, (int) $otherCard->cardStacks()->sum('card_stacks.amount'));
    }

    #[Test]
    public function another_users_container_is_refused_and_nothing_changes(): void
    {
        [$user, $deck, $deckCard, $stack] = $this->claimedCard(quantity: 2, claimed: 2, inDeckbox: true);
        $foreign = $this->container(User::factory()->create(), 'Not yours');

        $this->actingAs($user)
            ->patchJson($this->quantityUrl($deck, $deckCard), ['delta' => -1, 'move_to' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('move_to');
        $this->actingAs($user)
            ->deleteJson($this->cardUrl($deck, $deckCard), ['move_to' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('move_to');

        $this->assertSame(2, $deckCard->fresh()->quantity);
        $this->assertSame(2, $stack->fresh()->amount);
    }

    private function quantityUrl(Deck $deck, DeckCard $deckCard): string
    {
        return "/api/decks/{$deck->id}/cards/{$deckCard->id}/quantity";
    }

    private function cardUrl(Deck $deck, DeckCard $deckCard): string
    {
        return "/api/decks/{$deck->id}/cards/{$deckCard->id}";
    }

    /**
     * A finished mode-C deck of a new user with one card of `$quantity`
     * copies, `$claimed` of them claimed by one stack — in the deck's
     * deckbox, or in a binder while the deck has no deckbox.
     *
     * @return array{0: User, 1: Deck, 2: DeckCard, 3: CardStack, 4: Container}
     */
    private function claimedCard(int $quantity, int $claimed, bool $inDeckbox = false): array
    {
        $user = User::factory()->create(['collection_integration_enabled' => true]);
        $deck = $this->deck($user, 'Lands');
        $container = $this->container($user, $inDeckbox ? 'Deckbox' : 'Binder');
        if ($inDeckbox) {
            $deck->update(['container_id' => $container->id]);
        }
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => 'Forest',
            'searchable_name' => 'forest',
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 0,
            'color_identity' => 'G',
            'scryfall_uri' => 'https://example.com/forest',
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
            'name' => 'Forest',
            'searchable_name' => 'forest',
            'collector_number' => '294',
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 3,
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
            'quantity' => $quantity,
        ]);
        $stack = $this->stack($user, $deckCard, $container, $claimed);
        $deckCard->cardStacks()->attach($stack->id);

        return [$user, $deck, $deckCard, $stack, $container];
    }

    /**
     * An unclaimed nonfoil English stack of the deck card's printing.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function stack(User $user, DeckCard $deckCard, Container $container, int $amount, array $attributes = []): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $deckCard->default_card_id,
            'container_id' => $container->id,
            'amount' => $amount,
            'finish' => 1,
            'language' => 'en',
            ...$attributes,
        ]);
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
