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
 * The collection and container tables' row actions: the +/- buttons
 * (`PATCH /api/collection/cardstack/{id}/amount`) and the unclaim dialog,
 * which can move the freed stack to another container or to unsorted.
 */
class CardStackAmountAndUnclaimMoveTest extends TestCase
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
    public function plus_and_minus_change_the_amount_by_their_net_delta(): void
    {
        [$user, $stack] = $this->stack(amount: 3);

        $this->actingAs($user)
            ->patchJson("/api/collection/cardstack/{$stack->id}/amount", ['delta' => 2])
            ->assertOk()
            ->assertExactJson(['amount' => 5]);

        $this->actingAs($user)
            ->patchJson("/api/collection/cardstack/{$stack->id}/amount", ['delta' => -4])
            ->assertOk()
            ->assertExactJson(['amount' => 1]);

        $this->assertSame(1, $stack->fresh()->amount);
    }

    #[Test]
    public function the_last_card_is_not_taken_here_but_through_delete(): void
    {
        [$user, $stack] = $this->stack(amount: 2);

        $this->actingAs($user)
            ->patchJson("/api/collection/cardstack/{$stack->id}/amount", ['delta' => -2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('delta');

        $this->assertSame(2, $stack->fresh()->amount);
    }

    #[Test]
    public function the_amount_stops_at_the_edit_forms_maximum(): void
    {
        [$user, $stack] = $this->stack(amount: 65535);

        $this->actingAs($user)
            ->patchJson("/api/collection/cardstack/{$stack->id}/amount", ['delta' => 1])
            ->assertUnprocessable();
    }

    #[Test]
    public function a_zero_or_missing_delta_is_refused(): void
    {
        [$user, $stack] = $this->stack(amount: 2);

        $this->actingAs($user)->patchJson("/api/collection/cardstack/{$stack->id}/amount", ['delta' => 0])->assertUnprocessable();
        $this->actingAs($user)->patchJson("/api/collection/cardstack/{$stack->id}/amount", [])->assertUnprocessable();
    }

    #[Test]
    public function a_delta_that_is_no_whole_number_or_far_too_big_is_refused(): void
    {
        [$user, $stack] = $this->stack(amount: 2);

        foreach (['abc', 1.5, 70000, -70000] as $delta) {
            $this->actingAs($user)
                ->patchJson("/api/collection/cardstack/{$stack->id}/amount", ['delta' => $delta])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('delta');
        }

        $this->assertSame(2, $stack->fresh()->amount);
    }

    #[Test]
    public function only_the_owner_changes_the_amount(): void
    {
        [, $stack] = $this->stack(amount: 2);

        $this->actingAs(User::factory()->create())
            ->patchJson("/api/collection/cardstack/{$stack->id}/amount", ['delta' => 1])
            ->assertForbidden();

        $this->assertSame(2, $stack->fresh()->amount);
    }

    #[Test]
    public function unclaiming_without_a_target_leaves_the_stack_where_it_is(): void
    {
        [$user, $stack, $deckbox] = $this->claimedStack();

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims", ['return' => '/collection'])
            ->assertRedirect('/collection');

        $this->assertSame($deckbox->id, $stack->fresh()->container_id);
        $this->assertFalse($stack->fresh()->isClaimed());
    }

    #[Test]
    public function unclaiming_can_move_the_stack_to_another_container(): void
    {
        [$user, $stack] = $this->claimedStack();
        $binder = $this->container($user, 'Binder');

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims", ['move_to' => $binder->id, 'return' => '/collection'])
            ->assertRedirect('/collection')
            ->assertSessionHas('message', $this->message($user, moveTo: 'Binder'));

        $this->assertSame($binder->id, $stack->fresh()->container_id);
        $this->assertFalse($stack->fresh()->isClaimed());
    }

    #[Test]
    public function unclaiming_can_move_the_stack_to_unsorted(): void
    {
        [$user, $stack] = $this->claimedStack();

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims", ['move_to' => 'unsorted'])
            ->assertSessionHas('message', $this->message($user, moveTo: trans('collection.unsorted', [], $user->preferredLocale())));

        $this->assertNull($stack->fresh()->container_id);
    }

    #[Test]
    public function choosing_the_container_it_is_in_only_unclaims(): void
    {
        [$user, $stack, $deckbox] = $this->claimedStack();

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims", ['move_to' => $deckbox->id])
            ->assertSessionHas('message', $this->message($user));

        $this->assertSame($deckbox->id, $stack->fresh()->container_id);
        $this->assertFalse($stack->fresh()->isClaimed());
    }

    #[Test]
    public function a_stack_no_deck_reserves_says_so(): void
    {
        [$user, $stack] = $this->stack();

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims", ['move_to' => 'unsorted'])
            ->assertSessionHas('message', $this->message($user, claims: 0, moveTo: trans('collection.unsorted', [], $user->preferredLocale())));

        $this->assertNull($stack->fresh()->container_id);
    }

    #[Test]
    public function unclaiming_leaves_the_deck_list_alone_unless_asked(): void
    {
        [$user, $stack, , $deckCard] = $this->claimedStack();

        $this->actingAs($user)->delete("/collection/cardstack/{$stack->id}/claims");

        $this->assertSame(1, $deckCard->fresh()->quantity);
    }

    #[Test]
    public function unclaiming_can_remove_the_card_from_the_deck_list_too(): void
    {
        [$user, $stack, $deckbox, $deckCard] = $this->claimedStack();

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims", ['remove_from_decks' => true])
            ->assertSessionHas('message', $this->message($user, decksChanged: 1));

        $this->assertNull($deckCard->fresh());
        $this->assertSame($deckbox->id, $stack->fresh()->container_id);
        $this->assertSame(1, $stack->fresh()->amount);
    }

    #[Test]
    public function a_deck_card_other_stacks_back_too_only_loses_this_stacks_copies(): void
    {
        [$user, $stack, , $deckCard] = $this->claimedStack(stackAmount: 2, deckQuantity: 4);

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims", ['remove_from_decks' => true]);

        $this->assertSame(2, $deckCard->fresh()->quantity);
        $this->assertFalse($stack->fresh()->isClaimed());
    }

    #[Test]
    public function removing_from_the_deck_list_and_moving_go_together(): void
    {
        [$user, $stack, , $deckCard] = $this->claimedStack();
        $binder = $this->container($user, 'Binder');

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims", ['remove_from_decks' => '1', 'move_to' => $binder->id])
            ->assertSessionHas('message', $this->message($user, decksChanged: 1, moveTo: 'Binder'));

        $this->assertNull($deckCard->fresh());
        $this->assertSame($binder->id, $stack->fresh()->container_id);
    }

    #[Test]
    public function another_users_container_is_refused_and_nothing_changes(): void
    {
        [$user, $stack, $deckbox] = $this->claimedStack();
        $foreign = $this->container(User::factory()->create(), 'Not yours');

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}/claims", ['move_to' => $foreign->id])
            ->assertSessionHasErrors(['move_to' => trans('collection.errors.container_not_found', [], $user->preferredLocale())]);

        $this->assertSame($deckbox->id, $stack->fresh()->container_id);
        $this->assertTrue($stack->fresh()->isClaimed());
    }

    /**
     * A stack of a new user's, in a container.
     *
     * @return array{0: User, 1: CardStack, 2: Container}
     */
    private function stack(int $amount = 1): array
    {
        $user = User::factory()->create();
        $container = $this->container($user, 'Deckbox');
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => 'Sol Ring',
            'searchable_name' => 'sol ring',
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'scryfall_uri' => 'https://example.com/sol-ring',
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
            'name' => 'Sol Ring',
            'searchable_name' => 'sol ring',
            'collector_number' => '269',
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'uncommon',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
        $stack = CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $printing->id,
            'container_id' => $container->id,
            'amount' => $amount,
            'finish' => 1,
            'language' => 'en',
        ]);

        return [$user, $stack, $container];
    }

    /**
     * A stack in its deck's deckbox, claimed by a card of that deck.
     *
     * @return array{0: User, 1: CardStack, 2: Container, 3: DeckCard}
     */
    private function claimedStack(int $stackAmount = 1, int $deckQuantity = 1): array
    {
        [$user, $stack, $deckbox] = $this->stack($stackAmount);
        $deck = Deck::create([
            'user_id' => $user->id,
            'name' => 'Krenko',
            'format' => CardFormat::Commander->value,
            'state' => DeckState::Built->value,
            'collection_mode' => 'C',
        ]);
        $deckCard = DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $stack->defaultCard->oracle_id,
            'default_card_id' => $stack->default_card_id,
            'zone' => DeckZone::Main->value,
            'quantity' => $deckQuantity,
        ]);
        $deckCard->cardStacks()->attach($stack->id);

        return [$user, $stack, $deckbox, $deckCard];
    }

    /**
     * The unclaim's flash message in the user's own language: the unclaim,
     * then what else it did.
     */
    private function message(User $user, int $claims = 1, int $decksChanged = 0, ?string $moveTo = null): string
    {
        $locale = $user->preferredLocale();
        $message = trans_choice('collection.cardstack_unclaimed', $claims, ['name' => 'Sol Ring', 'count' => $claims], $locale);
        if ($decksChanged > 0) {
            $message .= ' '.trans_choice('collection.cardstack_removed_from_decks', $decksChanged, ['count' => $decksChanged], $locale);
        }
        if ($moveTo !== null) {
            $message .= ' '.trans('collection.cardstack_moved_to', ['container' => $moveTo], $locale);
        }

        return $message;
    }

    private function container(User $user, string $name): Container
    {
        return Container::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => 'binder',
            'sort_order' => 1,
        ]);
    }
}
