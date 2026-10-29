<?php

namespace Tests\Feature;

use App\Enums\CardCondition;
use App\Enums\CardFormat;
use App\Enums\CardLanguage;
use App\Enums\DeckCardRole;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\CardStackService;
use App\Services\DeckBulkAddCollectionService;
use App\Services\DeckCardAssignmentService;
use App\Services\DeckCardCopiesService;
use App\Services\DeckFinalizeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Finish, language and proxy of a deck card's copies — "one owner at a
 * time" ({@see DeckCardCopiesService}): the deck card plans the copies no
 * stack claims, a claimed copy is whatever its stack is, and data moves
 * only when a stack is minted from the plan or a claim covers the card.
 *
 * Fixtures disagree on purpose — a foil stack under a nonfoil plan, a
 * German plan beside an English stack — so a test can tell which owner a
 * value came from.
 */
class DeckCardCopiesTest extends TestCase
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

    /* ------------------------------------------------------------------ */
    /* The model's defaults */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_new_card_is_planned_as_an_english_nonfoil_real_card(): void
    {
        $card = $this->card($this->deck($this->user()), $this->printing('Sol Ring', 3));

        $card->refresh();
        $this->assertSame([Finish::Nonfoil, CardLanguage::En, false], [$card->finish, $card->language, $card->proxy]);
    }

    #[Test]
    public function a_foil_only_printing_is_planned_foil(): void
    {
        $card = $this->card($this->deck($this->user()), $this->printing("Atraxa, Praetors' Voice", 2));

        $this->assertSame(Finish::Foil, $card->refresh()->finish);
    }

    #[Test]
    public function an_etched_only_printing_is_planned_etched(): void
    {
        $card = $this->card($this->deck($this->user()), $this->printing('Sol Ring', 4));

        $this->assertSame(Finish::Etched, $card->refresh()->finish);
    }

    #[Test]
    public function a_finish_the_printing_lacks_falls_back_to_its_first(): void
    {
        $card = $this->card($this->deck($this->user()), $this->printing('Sol Ring', 1), 1, ['finish' => Finish::Foil]);

        $this->assertSame(Finish::Nonfoil, $card->refresh()->finish);
    }

    #[Test]
    public function switching_to_a_printing_without_the_planned_finish_falls_back(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $foilable = $this->printing('Lightning Bolt', 3);
        $alpha = $this->printing('Lightning Bolt', 1, oracle: $foilable->oracle);
        $card = $this->card($deck, $foilable, 1, ['finish' => Finish::Foil, 'language' => CardLanguage::De]);

        $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/cards/{$card->id}/printing", ['default_card_id' => $alpha->id])
            ->assertNoContent();

        $card->refresh();
        $this->assertSame([Finish::Nonfoil, CardLanguage::De], [$card->finish, $card->language]);
    }

    #[Test]
    public function switching_to_a_printing_that_has_the_finish_keeps_it(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $first = $this->printing('Lightning Bolt', 3);
        $second = $this->printing('Lightning Bolt', 3, oracle: $first->oracle);
        $card = $this->card($deck, $first, 1, ['finish' => Finish::Foil]);

        $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/cards/{$card->id}/printing", ['default_card_id' => $second->id])
            ->assertNoContent();

        $this->assertSame(Finish::Foil, $card->refresh()->finish);
    }

    /* ------------------------------------------------------------------ */
    /* Re-planning the unclaimed copies */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function re_planning_every_copy_changes_the_row(): void
    {
        $card = $this->card($this->deck($this->user()), $this->printing('Lightning Bolt', 3), 4);

        $result = DeckCardCopiesService::replan($card, 4, Finish::Foil, CardLanguage::De, true);

        $this->assertSame($card->id, $result->id);
        $card->refresh();
        $this->assertSame([4, Finish::Foil, CardLanguage::De, true], [$card->quantity, $card->finish, $card->language, $card->proxy]);
        $this->assertSame(1, DeckCard::query()->count());
    }

    #[Test]
    public function re_planning_some_copies_splits_them_into_a_row_of_their_own(): void
    {
        $category = null;
        $deck = $this->deck($this->user());
        $card = $this->card($deck, $this->printing('Lightning Bolt', 3), 4, ['zone' => DeckZone::Side->value]);

        $split = DeckCardCopiesService::replan($card, 1, Finish::Foil, CardLanguage::En, false);

        $this->assertNotSame($card->id, $split->id);
        $this->assertSame([3, Finish::Nonfoil], [$card->refresh()->quantity, $card->finish]);
        $split->refresh();
        $this->assertSame(
            [1, Finish::Foil, DeckZone::Side, $card->default_card_id, $category],
            [$split->quantity, $split->finish, $split->zone, $split->default_card_id, $split->category_id],
        );
    }

    #[Test]
    public function re_planning_to_the_same_values_changes_nothing(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 4, ['language' => CardLanguage::De]);
        $this->claim($card, $this->stack($user, $printing, 1));

        $result = DeckCardCopiesService::replan($card, 1, Finish::Nonfoil, CardLanguage::De, false);

        $this->assertSame($card->id, $result->id);
        $this->assertSame(1, DeckCard::query()->count());
        $this->assertSame(4, $card->refresh()->quantity);
    }

    #[Test]
    public function a_count_the_row_no_longer_has_is_cut_to_what_is_open(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 4);
        $stale = $card->replicate();
        $stale->id = $card->id;
        $stale->exists = true;
        $card->update(['quantity' => 2]);

        DeckCardCopiesService::replan($stale, 3, Finish::Foil, CardLanguage::En, false);

        $this->assertSame([2, Finish::Foil], [$card->refresh()->quantity, $card->finish]);
        $this->assertSame(1, DeckCard::query()->count());
    }

    #[Test]
    public function a_row_planned_back_like_its_sibling_folds_into_it(): void
    {
        $deck = $this->deck($this->user());
        $card = $this->card($deck, $this->printing('Lightning Bolt', 3), 4);
        $foil = DeckCardCopiesService::replan($card, 2, Finish::Foil, CardLanguage::En, false);

        $result = DeckCardCopiesService::replan($foil, 2, Finish::Nonfoil, CardLanguage::En, false);

        $this->assertSame($card->id, $result->id);
        $this->assertNull($foil->fresh());
        $this->assertSame(4, $card->refresh()->quantity);
    }

    #[Test]
    public function a_sibling_with_another_plan_does_not_fold(): void
    {
        $deck = $this->deck($this->user());
        $card = $this->card($deck, $this->printing('Lightning Bolt', 3), 4);
        $foil = DeckCardCopiesService::replan($card, 2, Finish::Foil, CardLanguage::En, false);

        DeckCardCopiesService::replan($foil, 2, Finish::Nonfoil, CardLanguage::De, false);

        $this->assertSame([2, 2], DeckCard::query()->orderBy('created_at')->pluck('quantity')->all());
    }

    #[Test]
    public function a_claimed_sibling_never_absorbs_a_row(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $claimed = $this->card($deck, $printing, 2);
        $this->claim($claimed, $this->stack($user, $printing, 2));
        $open = $this->card($deck, $printing, 2, ['finish' => Finish::Foil]);

        DeckCardCopiesService::replan($open, 2, Finish::Nonfoil, CardLanguage::En, false);

        $this->assertSame(2, $open->refresh()->quantity);
        $this->assertSame(2, $claimed->refresh()->quantity);
    }

    #[Test]
    public function on_a_partly_claimed_card_only_the_open_copies_are_re_planned(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 4);
        $stack = $this->stack($user, $printing, 2);
        $this->claim($card, $stack);

        DeckCardCopiesService::replan($card, 2, Finish::Foil, CardLanguage::Ja, false);

        $this->assertSame([4, Finish::Foil, CardLanguage::Ja], [$card->refresh()->quantity, $card->finish, $card->language]);
        $this->assertSame([Finish::Nonfoil, CardLanguage::En], [$stack->refresh()->finish, $stack->language]);
        $this->assertSame([
            ['count' => 2, 'finish' => 'nonfoil', 'language' => 'en', 'proxy' => false, 'claimed' => true],
            ['count' => 2, 'finish' => 'foil', 'language' => 'ja', 'proxy' => false, 'claimed' => false],
        ], DeckCardCopiesService::groupsFor(collect([$card]), true)[$card->id]);
    }

    /* ------------------------------------------------------------------ */
    /* The endpoints */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_copies_endpoint_lists_each_claimed_stack_then_the_plan(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 4, ['language' => CardLanguage::De]);
        $binder = Container::create(['user_id' => $user->id, 'name' => 'Binder', 'type' => 'binder', 'sort_order' => 1]);
        $stack = $this->stack($user, $printing, 1, ['finish' => Finish::Foil, 'condition' => 'played', 'container_id' => $binder->id]);
        $this->claim($card, $stack);
        $other = Deck::create(['user_id' => $user->id, 'name' => 'Other Deck', 'format' => CardFormat::Legacy->value]);
        $this->claim($this->card($other, $printing, 1), $stack);

        $this->actingAs($user)
            ->getJson($this->url($deck, $card))
            ->assertOk()
            ->assertJsonPath('integration', true)
            ->assertJsonPath('claimed', 1)
            ->assertJsonPath('languages.1', 'de')
            ->assertJsonPath('conditions.0', 'mint')
            ->assertJsonPath('groups', [
                [
                    'stack_id' => $stack->id,
                    'count' => 1,
                    'splittable' => true,
                    'finish' => 'foil',
                    'language' => 'en',
                    'proxy' => false,
                    'condition' => 'played',
                    'finishes' => ['nonfoil', 'foil'],
                    'container' => ['id' => $binder->id, 'name' => 'Binder'],
                    'other_decks' => ['Other Deck'],
                ],
                [
                    'stack_id' => null,
                    'count' => 3,
                    'splittable' => true,
                    'finish' => 'nonfoil',
                    'language' => 'de',
                    'proxy' => false,
                    'condition' => null,
                    'finishes' => ['nonfoil', 'foil'],
                    'container' => null,
                    'other_decks' => [],
                ],
            ]);
    }

    #[Test]
    public function a_fully_claimed_card_lists_only_its_stacks(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 1);
        $card = $this->card($deck, $printing, 2);
        $stack = $this->stack($user, $printing, 2);
        $this->claim($card, $stack);

        $this->actingAs($user)
            ->getJson($this->url($deck, $card))
            ->assertOk()
            ->assertJsonPath('claimed', 2)
            ->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.stack_id', $stack->id)
            ->assertJsonPath('groups.0.container', null);
    }

    #[Test]
    public function with_the_master_switch_off_every_copy_is_listed_as_planned(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $claimed = $this->card($deck, $printing, 4);
        $this->claim($claimed, $this->stack($user, $printing, 1));
        $open = $this->card($deck, $this->printing('Sol Ring', 3), 2);
        $user->update(['collection_integration_enabled' => false]);

        $this->actingAs($user)
            ->getJson($this->url($deck, $claimed))
            ->assertJsonPath('integration', false)
            ->assertJsonPath('claimed', 0)
            ->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.stack_id', null)
            ->assertJsonPath('groups.0.count', 4)
            ->assertJsonPath('groups.0.splittable', false);
        $this->actingAs($user)
            ->getJson($this->url($deck, $open))
            ->assertJsonPath('groups.0.count', 2)
            ->assertJsonPath('groups.0.splittable', true);
    }

    #[Test]
    public function with_the_master_switch_off_a_claimed_card_is_re_planned_only_whole(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 4);
        $this->claim($card, $this->stack($user, $printing, 1));
        $user->update(['collection_integration_enabled' => false]);
        $foil = ['finish' => 'foil', 'language' => 'en', 'proxy' => false];

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), ['count' => 2, ...$foil])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('count');
        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), ['count' => 4, ...$foil])
            ->assertNoContent();

        $this->assertSame([4, Finish::Foil], [$card->refresh()->quantity, $card->finish]);
        $this->assertSame(1, DeckCard::query()->count());
    }

    #[Test]
    public function editing_copies_re_plans_them(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $card = $this->card($deck, $this->printing('Lightning Bolt', 3), 4);

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), ['count' => 1, 'finish' => 'foil', 'language' => 'fr', 'proxy' => true])
            ->assertNoContent();

        $split = DeckCard::query()->where('id', '!=', $card->id)->sole();
        $this->assertSame([1, Finish::Foil, CardLanguage::Fr, true], [$split->quantity, $split->finish, $split->language, $split->proxy]);
        $this->assertSame(3, $card->refresh()->quantity);
    }

    #[Test]
    public function more_copies_than_are_open_are_refused(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 4);
        $this->claim($card, $this->stack($user, $printing, 2));

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), ['count' => 3, 'finish' => 'foil', 'language' => 'en', 'proxy' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('count');

        $this->assertSame(Finish::Nonfoil, $card->refresh()->finish);
    }

    #[Test]
    public function a_finish_the_printing_does_not_exist_in_is_refused(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $card = $this->card($deck, $this->printing('Lightning Bolt', 1), 1);

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), ['count' => 1, 'finish' => 'foil', 'language' => 'en', 'proxy' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('finish');
    }

    #[Test]
    public function an_unknown_language_is_refused(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $card = $this->card($deck, $this->printing('Lightning Bolt', 1), 1);

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), ['count' => 1, 'finish' => 'nonfoil', 'language' => 'xx', 'proxy' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('language');
    }

    #[Test]
    public function only_the_owner_sees_and_edits_copies_and_only_of_that_deck(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $card = $this->card($deck, $this->printing('Lightning Bolt', 3), 1);
        $otherDeck = $this->deck($user);
        $payload = ['count' => 1, 'finish' => 'foil', 'language' => 'en', 'proxy' => false];

        $this->actingAs($this->user())->getJson($this->url($deck, $card))->assertForbidden();
        $this->actingAs($this->user())->patchJson($this->url($deck, $card), $payload)->assertForbidden();
        $this->actingAs($user)->getJson($this->url($otherDeck, $card))->assertForbidden();
        $this->actingAs($user)->patchJson($this->url($otherDeck, $card), $payload)->assertForbidden();

        $this->assertSame(Finish::Nonfoil, $card->refresh()->finish);
    }

    /* ------------------------------------------------------------------ */
    /* Editing a claimed copy — the collection's cards */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function editing_every_copy_of_a_claimed_stack_changes_the_stack_not_the_plan(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 2, ['language' => CardLanguage::Fr]);
        $stack = $this->stack($user, $printing, 2);
        $this->claim($card, $stack);

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), [
                'stack_id' => $stack->id, 'count' => 2, 'finish' => 'foil', 'language' => 'ja', 'proxy' => true, 'condition' => 'good',
            ])
            ->assertNoContent();

        $stack->refresh();
        $this->assertSame(
            [2, Finish::Foil, CardLanguage::Ja, true, CardCondition::Good],
            [$stack->amount, $stack->finish, $stack->language, $stack->proxy, $stack->condition],
        );
        $this->assertSame([Finish::Nonfoil, CardLanguage::Fr, false], [$card->refresh()->finish, $card->language, $card->proxy]);
        $this->assertSame(1, CardStack::query()->count());
    }

    #[Test]
    public function editing_some_copies_of_a_stack_splits_them_off_with_every_claim(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 3);
        $stack = $this->stack($user, $printing, 3, ['condition' => 'mint']);
        $this->claim($card, $stack);
        $otherCard = $this->card($this->deck($user), $printing, 1);
        $this->claim($otherCard, $stack);

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), [
                'stack_id' => $stack->id, 'count' => 1, 'finish' => 'foil', 'language' => 'en', 'proxy' => false, 'condition' => 'mint',
            ])
            ->assertNoContent();

        $split = CardStack::query()->where('id', '!=', $stack->id)->sole();
        $this->assertSame([1, Finish::Foil, CardCondition::Mint], [$split->amount, $split->finish, $split->condition]);
        $this->assertSame([2, Finish::Nonfoil], [$stack->refresh()->amount, $stack->finish]);
        $this->assertEqualsCanonicalizing([$card->id, $otherCard->id], $split->deckCards()->pluck('deck_cards.id')->all());
        $this->assertEqualsCanonicalizing([$card->id, $otherCard->id], $stack->deckCards()->pluck('deck_cards.id')->all());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: mixed}>
     */
    public static function singleStackEdits(): array
    {
        $base = ['finish' => 'nonfoil', 'language' => 'en', 'proxy' => false, 'condition' => 'good'];

        return [
            'condition only' => [[...$base, 'condition' => 'played'], 'condition', CardCondition::Played],
            'language only' => [[...$base, 'language' => 'it'], 'language', CardLanguage::It],
            'proxy only' => [[...$base, 'proxy' => true], 'proxy', true],
            'finish only' => [[...$base, 'finish' => 'foil'], 'finish', Finish::Foil],
        ];
    }

    /**
     * @param  array<string, mixed>  $edit
     */
    #[Test]
    #[DataProvider('singleStackEdits')]
    public function a_stack_edit_changing_one_value_is_saved(array $edit, string $field, mixed $expected): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 1);
        $stack = $this->stack($user, $printing, 1, ['condition' => 'good']);
        $this->claim($card, $stack);

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), ['stack_id' => $stack->id, 'count' => 1, ...$edit])
            ->assertNoContent();

        $this->assertSame($expected, $stack->refresh()->{$field});
    }

    #[Test]
    public function a_stack_edit_without_a_condition_keeps_the_stacks(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 1);
        $stack = $this->stack($user, $printing, 1, ['condition' => 'good']);
        $this->claim($card, $stack);

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), [
                'stack_id' => $stack->id, 'count' => 1, 'finish' => 'foil', 'language' => 'en', 'proxy' => false,
            ])
            ->assertNoContent();

        $this->assertSame([Finish::Foil, CardCondition::Good], [$stack->refresh()->finish, $stack->condition]);
    }

    #[Test]
    public function a_copy_edited_and_edited_back_folds_into_its_stack_again(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 2);
        $stack = $this->stack($user, $printing, 2);
        $this->claim($card, $stack);
        $edit = ['count' => 1, 'language' => 'en', 'proxy' => false];

        $this->actingAs($user)->patchJson($this->url($deck, $card), ['stack_id' => $stack->id, ...$edit, 'finish' => 'foil'])->assertNoContent();
        $foil = CardStack::query()->where('id', '!=', $stack->id)->sole();
        $this->actingAs($user)->patchJson($this->url($deck, $card), ['stack_id' => $foil->id, ...$edit, 'finish' => 'nonfoil'])->assertNoContent();

        $this->assertNull($foil->fresh());
        $this->assertSame(2, $stack->refresh()->amount);
        $this->assertSame([$card->id], $stack->deckCards()->pluck('deck_cards.id')->all());
    }

    #[Test]
    public function a_stack_claimed_by_other_decks_too_does_not_fold_into_one_claimed_here_alone(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 2);
        $shared = $this->stack($user, $printing, 1, ['finish' => Finish::Foil]);
        $own = $this->stack($user, $printing, 1);
        $this->claim($card, $shared);
        $this->claim($card, $own);
        $this->claim($this->card($this->deck($user), $printing, 1), $shared);

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), [
                'stack_id' => $shared->id, 'count' => 1, 'finish' => 'nonfoil', 'language' => 'en', 'proxy' => false,
            ])
            ->assertNoContent();

        $this->assertSame(2, CardStack::query()->count());
        $this->assertSame(Finish::Nonfoil, $shared->refresh()->finish);
    }

    #[Test]
    public function the_other_decks_leave_out_this_deck_and_name_each_deck_once(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 1);
        $stack = $this->stack($user, $printing, 3);
        $this->claim($card, $stack);
        $this->claim($this->card($deck, $printing, 1, ['zone' => DeckZone::Side->value]), $stack);
        $twin = Deck::create(['user_id' => $user->id, 'name' => 'Twin', 'format' => CardFormat::Legacy->value]);
        $sibling = Deck::create(['user_id' => $user->id, 'name' => 'Twin', 'format' => CardFormat::Legacy->value]);
        $this->claim($this->card($twin, $printing, 1), $stack);
        $this->claim($this->card($sibling, $printing, 1), $stack);

        $this->actingAs($user)
            ->getJson($this->url($deck, $card))
            ->assertJsonPath('groups.0.other_decks', ['Twin', 'Twin']);
    }

    #[Test]
    public function a_stack_of_another_user_is_refused_even_when_claimed_for_the_card(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 1);
        $foreign = $this->stack($this->user(), $printing, 1);
        $this->claim($card, $foreign);

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), [
                'stack_id' => $foreign->id, 'count' => 1, 'finish' => 'foil', 'language' => 'en', 'proxy' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stack_id');
    }

    #[Test]
    public function an_edited_stack_shows_on_the_deck_and_reprices_it(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $printing->update(['price_eur' => 1.00, 'price_eur_foil' => 100.00]);
        $card = $this->card($deck, $printing, 2);
        $stack = $this->stack($user, $printing, 2);
        $this->claim($card, $stack);

        $this->actingAs($user)->patchJson($this->url($deck, $card), [
            'stack_id' => $stack->id, 'count' => 1, 'finish' => 'foil', 'language' => 'en', 'proxy' => false,
        ])->assertNoContent();

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deck.total_worth', 101)
                ->where('cards.0.copies.0', ['count' => 1, 'finish' => 'nonfoil', 'language' => 'en', 'proxy' => false, 'claimed' => true])
                ->where('cards.0.copies.1', ['count' => 1, 'finish' => 'foil', 'language' => 'en', 'proxy' => false, 'claimed' => true]));
    }

    #[Test]
    public function a_stack_not_claimed_for_this_card_is_refused(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 1);
        $free = $this->stack($user, $printing, 1);
        $elsewhere = $this->stack($user, $printing, 1);
        $this->claim($this->card($this->deck($user), $printing, 1), $elsewhere);
        $foreign = $this->stack($this->user(), $printing, 1);

        foreach ([$free, $elsewhere, $foreign] as $stack) {
            $this->actingAs($user)
                ->patchJson($this->url($deck, $card), [
                    'stack_id' => $stack->id, 'count' => 1, 'finish' => 'foil', 'language' => 'en', 'proxy' => false,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('stack_id');
        }

        $this->assertSame(0, CardStack::query()->where('finish', Finish::Foil->value)->count());
    }

    #[Test]
    public function a_stack_edit_is_held_to_the_stacks_amount_and_printing(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $card = $this->card($deck, $this->printing('Lightning Bolt', 3), 2);
        $alpha = $this->printing('Lightning Bolt', 1, oracle: $card->oracleCard);
        $stack = $this->stack($user, $alpha, 2);
        $this->claim($card, $stack);
        $edit = ['stack_id' => $stack->id, 'language' => 'en', 'proxy' => false];

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), [...$edit, 'count' => 3, 'finish' => 'nonfoil'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('count');
        // The stack is an Alpha printing without foil, though the card's own printing has one.
        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), [...$edit, 'count' => 2, 'finish' => 'foil'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('finish');
        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), [...$edit, 'count' => 2, 'finish' => 'nonfoil', 'condition' => 'pristine'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('condition');
    }

    #[Test]
    public function with_the_master_switch_off_no_stack_can_be_edited(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 1);
        $stack = $this->stack($user, $printing, 1);
        $this->claim($card, $stack);
        $user->update(['collection_integration_enabled' => false]);

        $this->actingAs($user)
            ->patchJson($this->url($deck, $card), [
                'stack_id' => $stack->id, 'count' => 1, 'finish' => 'foil', 'language' => 'en', 'proxy' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stack_id');

        $this->assertSame(Finish::Nonfoil, $stack->refresh()->finish);
    }

    #[Test]
    public function editing_a_stack_to_the_values_it_has_changes_nothing(): void
    {
        $user = $this->user();
        $stack = $this->stack($user, $this->printing('Lightning Bolt', 3), 3, ['condition' => 'good']);

        $result = DeckCardCopiesService::editClaimedStack($stack, 1, Finish::Nonfoil, CardLanguage::En, false, CardCondition::Good);

        $this->assertSame($stack->id, $result->id);
        $this->assertSame(1, CardStack::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /* What the deck page shows */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_claimed_copy_shows_its_stack_not_the_plan(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 1, ['language' => CardLanguage::De]);
        $this->claim($card, $this->stack($user, $printing, 1, ['finish' => Finish::Foil, 'proxy' => true]));

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cards.0.copies', [
                ['count' => 1, 'finish' => 'foil', 'language' => 'en', 'proxy' => true, 'claimed' => true],
            ]));
    }

    #[Test]
    public function a_partly_claimed_card_shows_both_its_stack_and_its_plan(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 4, ['finish' => Finish::Foil]);
        $this->claim($card, $this->stack($user, $printing, 1));

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cards.0.copies', [
                ['count' => 1, 'finish' => 'nonfoil', 'language' => 'en', 'proxy' => false, 'claimed' => true],
                ['count' => 3, 'finish' => 'foil', 'language' => 'en', 'proxy' => false, 'claimed' => false],
            ]));
    }

    #[Test]
    public function a_visitor_sees_every_copy_as_planned(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $deck->update(['visibility' => 'public']);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 2, ['language' => CardLanguage::De]);
        $this->claim($card, $this->stack($user, $printing, 2, ['finish' => Finish::Foil]));

        $this->actingAs($this->user())
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cards.0.copies', [
                ['count' => 2, 'finish' => 'nonfoil', 'language' => 'de', 'proxy' => false, 'claimed' => false],
            ]));
    }

    #[Test]
    public function with_the_master_switch_off_the_owner_sees_the_plan_too(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 1);
        $this->claim($card, $this->stack($user, $printing, 1, ['finish' => Finish::Foil]));
        $user->update(['collection_integration_enabled' => false]);

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cards.0.copies', [
                ['count' => 1, 'finish' => 'nonfoil', 'language' => 'en', 'proxy' => false, 'claimed' => false],
            ]));
    }

    #[Test]
    public function the_command_zone_and_the_companion_carry_their_copies(): void
    {
        $user = $this->user();
        $deck = $this->deck($user, CardFormat::Commander);
        $commander = $this->card($deck, $this->printing('Krenko, Mob Boss', 3), 1, [
            'zone' => DeckZone::Command->value,
            'role' => DeckCardRole::Commander->value,
            'finish' => Finish::Foil,
        ]);
        $this->card($deck, $this->printing('Lurrus of the Dream-Den', 3), 1, [
            'zone' => DeckZone::Companion->value,
            'role' => DeckCardRole::Companion->value,
            'language' => CardLanguage::Ja,
        ]);

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commanders.0.deck_card_id', $commander->id)
                ->where('commanders.0.copies.0.finish', 'foil')
                ->where('companion.copies.0.language', 'ja'));
    }

    /* ------------------------------------------------------------------ */
    /* Hand-offs: stacks minted from the plan, plans adopted from claims */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function add_all_to_collection_mints_stacks_as_planned(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $card = $this->card($deck, $this->printing('Lightning Bolt', 3), 2, [
            'finish' => Finish::Foil, 'language' => CardLanguage::De, 'proxy' => true,
        ]);

        DeckBulkAddCollectionService::addAll($deck, null, false);

        $stack = $card->cardStacks()->sole();
        $this->assertSame(
            [2, Finish::Foil, CardLanguage::De, true, null],
            [$stack->amount, $stack->finish, $stack->language, $stack->proxy, $stack->condition],
        );
    }

    #[Test]
    public function a_bought_copy_is_minted_as_planned(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 1, ['finish' => Finish::Foil, 'language' => CardLanguage::It]);
        $this->stack($user, $printing, 1);

        DeckFinalizeService::persistAssignments($deck, [], [$card->id => true], null);

        $stack = $card->cardStacks()->sole();
        $this->assertSame([Finish::Foil, CardLanguage::It, false], [$stack->finish, $stack->language, $stack->proxy]);
        $this->assertSame(2, CardStack::query()->count(), 'a foil plan must not merge into the nonfoil stack');
    }

    #[Test]
    public function a_stack_covering_the_card_hands_it_its_values(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 2);
        $stack = $this->stack($user, $printing, 3, ['finish' => Finish::Foil, 'language' => CardLanguage::De]);

        DeckCardAssignmentService::replaceAssignedStack($card, $stack);

        $this->assertSame([Finish::Foil, CardLanguage::De], [$card->refresh()->finish, $card->language]);
    }

    #[Test]
    public function a_stack_covering_part_of_the_card_leaves_the_plan(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 4, ['language' => CardLanguage::Fr]);
        $stack = $this->stack($user, $printing, 2, ['finish' => Finish::Foil]);

        DeckCardAssignmentService::replaceAssignedStack($card, $stack);

        $this->assertSame([Finish::Nonfoil, CardLanguage::Fr], [$card->refresh()->finish, $card->language]);
    }

    #[Test]
    public function stacks_that_disagree_leave_the_plan(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 2, ['language' => CardLanguage::Fr]);
        $foil = $this->stack($user, $printing, 1, ['finish' => Finish::Foil]);
        $plain = $this->stack($user, $printing, 1);

        DeckFinalizeService::persistAssignments($deck, [$card->id => [$foil->id, $plain->id]], [], null);

        $this->assertSame(2, $card->cardStacks()->count());
        $this->assertSame([Finish::Nonfoil, CardLanguage::Fr], [$card->refresh()->finish, $card->language]);
    }

    #[Test]
    public function a_partial_bulk_claim_adopts_on_the_claimed_row_and_keeps_the_plan_on_the_rest(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $card = $this->card($deck, $printing, 4, ['language' => CardLanguage::Fr, 'proxy' => true]);
        $stack = $this->stack($user, $printing, 1, ['finish' => Finish::Foil]);

        DeckFinalizeService::persistAssignments($deck, [$card->id => [$stack->id]], [], null);

        $claimed = $card->refresh();
        $leftover = DeckCard::query()->where('id', '!=', $card->id)->sole();
        $this->assertSame([1, Finish::Foil, CardLanguage::En, false], [$claimed->quantity, $claimed->finish, $claimed->language, $claimed->proxy]);
        $this->assertSame([3, Finish::Nonfoil, CardLanguage::Fr, true], [$leftover->quantity, $leftover->finish, $leftover->language, $leftover->proxy]);
    }

    #[Test]
    public function splitting_a_proxy_stack_keeps_both_halves_proxies(): void
    {
        $user = $this->user();
        $stack = $this->stack($user, $this->printing('Lightning Bolt', 1), 4, ['proxy' => true]);

        $split = CardStackService::splitStack($stack, 1);

        $this->assertTrue($split->refresh()->proxy);
        $this->assertTrue($stack->refresh()->proxy);
    }

    /* ------------------------------------------------------------------ */
    /* Rows that carry a plan along */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_copy_moved_to_the_sideboard_keeps_its_plan_and_merges_only_with_its_like(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $main = $this->card($deck, $printing, 3, ['finish' => Finish::Foil]);
        $sideNonfoil = $this->card($deck, $printing, 1, ['zone' => DeckZone::Side->value]);

        $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/cards/{$main->id}/move-zone", ['zone' => 'side'])
            ->assertOk();

        $sideFoil = DeckCard::query()->where('zone', 'side')->where('finish', Finish::Foil->value)->sole();
        $this->assertSame(1, $sideFoil->quantity);
        $this->assertSame(1, $sideNonfoil->refresh()->quantity);

        $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/cards/{$main->id}/move-zone", ['zone' => 'side'])
            ->assertOk();

        $this->assertSame(2, $sideFoil->refresh()->quantity);
    }

    #[Test]
    public function the_move_names_the_rows_it_merged_and_both_rows_copies(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $printing = $this->printing('Lightning Bolt', 3);
        $main = $this->card($deck, $printing, 3, ['finish' => Finish::Foil]);
        $sideA = $this->card($deck, $printing, 1, ['zone' => DeckZone::Side->value, 'finish' => Finish::Foil]);
        $sideB = $this->card($deck, $printing, 1, ['zone' => DeckZone::Side->value, 'finish' => Finish::Foil]);
        $sideNonfoil = $this->card($deck, $printing, 1, ['zone' => DeckZone::Side->value]);

        $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/cards/{$main->id}/move-zone", ['zone' => 'side'])
            ->assertOk()
            ->assertJson([
                'source_quantity' => 2,
                'source_copies' => [['count' => 2, 'finish' => 'foil', 'claimed' => false]],
                'target_id' => $sideA->id,
                'target_quantity' => 3,
                'target_copies' => [['count' => 3, 'finish' => 'foil', 'claimed' => false]],
                'merged_ids' => [$sideB->id],
            ]);

        $this->assertNotNull($sideNonfoil->fresh());
    }

    #[Test]
    public function splitting_a_card_across_printings_keeps_its_plan(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $first = $this->printing('Lightning Bolt', 3);
        $foilable = $this->printing('Lightning Bolt', 3, oracle: $first->oracle);
        $alpha = $this->printing('Lightning Bolt', 1, oracle: $first->oracle);
        $card = $this->card($deck, $first, 3, ['finish' => Finish::Foil, 'language' => CardLanguage::De]);

        $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/cards/{$card->id}/split", ['splits' => [
                ['default_card_id' => $first->id, 'quantity' => 1],
                ['default_card_id' => $foilable->id, 'quantity' => 1],
                ['default_card_id' => $alpha->id, 'quantity' => 1],
            ]])
            ->assertNoContent();

        $rows = DeckCard::query()->get()->keyBy('default_card_id');
        $this->assertSame([Finish::Foil, CardLanguage::De], [$rows[$foilable->id]->finish, $rows[$foilable->id]->language]);
        $this->assertSame([Finish::Nonfoil, CardLanguage::De], [$rows[$alpha->id]->finish, $rows[$alpha->id]->language]);
    }

    #[Test]
    public function a_split_that_starts_on_a_printing_without_the_finish_keeps_the_plan_for_the_rest(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $foilable = $this->printing('Lightning Bolt', 3);
        $alpha = $this->printing('Lightning Bolt', 1, oracle: $foilable->oracle);
        $card = $this->card($deck, $foilable, 3, ['finish' => Finish::Foil]);

        $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/cards/{$card->id}/split", ['splits' => [
                ['default_card_id' => $alpha->id, 'quantity' => 2],
                ['default_card_id' => $foilable->id, 'quantity' => 1],
            ]])
            ->assertNoContent();

        $rows = DeckCard::query()->get()->keyBy('default_card_id');
        $this->assertSame(Finish::Nonfoil, $rows[$alpha->id]->finish);
        $this->assertSame(Finish::Foil, $rows[$foilable->id]->finish);
    }

    /* ------------------------------------------------------------------ */
    /* The unclaimed page's shopping list */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_shopping_list_says_how_the_open_copies_are_planned(): void
    {
        $user = $this->user();
        $deck = $this->deck($user);
        $this->card($deck, $this->printing('Lightning Bolt', 3), 2, [
            'finish' => Finish::Foil, 'language' => CardLanguage::De, 'proxy' => true,
        ]);

        $csv = $this->actingAs($user)->get(route('decks.unclaimed.export', $deck))->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));

        $this->assertSame(['finish', 'language', 'proxy'], array_slice($rows[0], 7));
        $this->assertSame(['foil', 'de', 'true'], array_slice($rows[1], 7));

        $this->actingAs($user)
            ->get(route('decks.unclaimed', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.0.finish', 'foil')
                ->where('cards.0.language', 'de')
                ->where('cards.0.proxy', true));
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    private function url(Deck $deck, DeckCard $card): string
    {
        return "/api/decks/{$deck->id}/cards/{$card->id}/copies";
    }

    private function user(): User
    {
        return User::factory()->create(['collection_integration_enabled' => true, 'currency' => 'eur']);
    }

    private function deck(User $user, CardFormat $format = CardFormat::Legacy): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'Deck '.Str::random(4),
            'format' => $format->value,
            'state' => DeckState::Built->value,
            'collection_mode' => 'C',
        ]);
    }

    /**
     * A printing — of a new oracle card unless `$oracle` names one.
     */
    private function printing(string $name, int $finishes, ?OracleCard $oracle = null): DefaultCard
    {
        $oracle ??= OracleCard::create([
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
        $set = Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2020-01-01',
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
            'finishes' => $finishes,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(Deck $deck, DefaultCard $printing, int $quantity = 1, array $attributes = []): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $printing->oracle_id,
            'default_card_id' => $printing->id,
            'zone' => DeckZone::Main->value,
            'quantity' => $quantity,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stack(User $user, DefaultCard $printing, int $amount, array $attributes = []): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $printing->id,
            'container_id' => null,
            'amount' => $amount,
            'finish' => Finish::Nonfoil,
            'language' => CardLanguage::En,
            'proxy' => false,
            ...$attributes,
        ]);
    }

    private function claim(DeckCard $card, CardStack $stack): void
    {
        $card->cardStacks()->attach($stack->id);
    }
}
