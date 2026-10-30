<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Enums\ImportSource;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A stack a deck claims is the deck's business, whichever collection-side
 * path reaches it: adding cards (the add form, a deck's "bought new", the
 * CSV import) never merges into it, the edit form and the tables' +/-
 * buttons never change its amount, and moving a whole container leaves it
 * where it is.
 */
class CardStackClaimLockTest extends TestCase
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
    public function adding_cards_starts_a_stack_beside_a_claimed_twin(): void
    {
        [$user, $deckCard, $claimed, $binder] = $this->claimedStack(amount: 1);

        $this->actingAs($user)
            ->post('/collection/add', $this->addForm($deckCard, $binder, amount: 2))
            ->assertRedirect("/containers/{$binder->id}")
            ->assertSessionHas('message', trans('collection.card_added_to_container', ['name' => 'Forest', 'container' => 'Binder'], $user->preferredLocale()));

        $this->assertSame(1, $claimed->fresh()->amount);
        $added = CardStack::query()->whereKeyNot($claimed->id)->sole();
        $this->assertSame(2, $added->amount);
        $this->assertSame($binder->id, $added->container_id);
        $this->assertFalse($added->isClaimed());
    }

    #[Test]
    public function adding_cards_still_merges_into_an_unclaimed_twin_beside_a_claimed_one(): void
    {
        [$user, $deckCard, $claimed, $binder] = $this->claimedStack(amount: 1);
        $twin = $this->stack($user, $deckCard, $binder, 3);

        $this->actingAs($user)
            ->post('/collection/add', $this->addForm($deckCard, $binder, amount: 2))
            ->assertSessionHas('message', trans('collection.amount_changed', ['name' => 'Forest', 'amount' => 5], $user->preferredLocale()));

        $this->assertSame(1, $claimed->fresh()->amount);
        $this->assertSame(5, $twin->fresh()->amount);
        $this->assertSame(2, CardStack::query()->count());
    }

    #[Test]
    public function buying_new_for_another_deck_never_takes_over_a_stack_one_deck_claims(): void
    {
        [$user, $deckCard, $claimed] = $this->claimedStack(amount: 1, inContainer: false);
        $other = $this->deck($user, 'Elves');
        $otherCard = $this->deckCard($other, $deckCard, quantity: 1);

        $this->actingAs($user)
            ->post("/decks/{$other->id}/unclaimed/buy", ['bought' => [$otherCard->id]])
            ->assertRedirect("/decks/{$other->id}/unclaimed");

        $this->assertSame(1, $claimed->fresh()->amount);
        $this->assertSame([$deckCard->id], $claimed->fresh()->deckCards()->pluck('deck_cards.id')->all());
        $bought = $otherCard->cardStacks()->sole();
        $this->assertNotSame($claimed->id, $bought->id);
        $this->assertSame(1, $bought->amount);
    }

    #[Test]
    public function a_csv_import_starts_a_stack_beside_a_claimed_twin(): void
    {
        [$user, $deckCard, $claimed] = $this->claimedStack(amount: 1, inContainer: false);

        $result = $this->importCsv($user, $deckCard, count: 2);

        $this->assertSame(['imported' => 1, 'merged' => 0], ['imported' => $result['imported'], 'merged' => $result['merged']]);
        $this->assertSame(1, $claimed->fresh()->amount);
        $imported = CardStack::query()->whereKeyNot($claimed->id)->sole();
        $this->assertSame(2, $imported->amount);
        $this->assertNull($imported->container_id);
        $this->assertFalse($imported->isClaimed());
    }

    #[Test]
    public function a_csv_import_grows_the_unclaimed_twin_and_not_the_claimed_one(): void
    {
        [$user, $deckCard] = $this->claimedStack(amount: 1, inContainer: false, claimedFirst: false);
        $twin = CardStack::query()->whereDoesntHave('deckCards')->sole();
        $claimed = CardStack::query()->whereHas('deckCards')->sole();

        $result = $this->importCsv($user, $deckCard, count: 2);

        $this->assertSame(['imported' => 0, 'merged' => 1], ['imported' => $result['imported'], 'merged' => $result['merged']]);
        $this->assertSame(1, $claimed->fresh()->amount);
        $this->assertSame(3, $twin->fresh()->amount);
    }

    #[Test]
    public function a_csv_import_never_turns_real_cards_into_proxies(): void
    {
        [$user, $deckCard] = $this->claimedStack(amount: 1, inContainer: false);
        $proxies = CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $deckCard->default_card_id,
            'container_id' => null,
            'amount' => 4,
            'finish' => 1,
            'language' => 'en',
            'proxy' => true,
        ]);

        $result = $this->importCsv($user, $deckCard, count: 2);

        $this->assertSame(['imported' => 1, 'merged' => 0], ['imported' => $result['imported'], 'merged' => $result['merged']]);
        $this->assertSame(4, $proxies->fresh()->amount);
        $imported = CardStack::query()->whereKeyNot($proxies->id)->whereDoesntHave('deckCards')->sole();
        $this->assertSame(2, $imported->amount);
        $this->assertFalse($imported->proxy);
    }

    #[Test]
    public function the_edit_form_refuses_a_new_amount_for_a_claimed_stack(): void
    {
        [$user, $deckCard, $claimed, $binder] = $this->claimedStack(amount: 2);

        $this->actingAs($user)
            ->patchJson("/collection/cardstack/{$claimed->id}", $this->editForm($binder, amount: 3))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => trans('collection.errors.cannot_change_amount_of_claimed_stack', [], $user->preferredLocale())])
            ->assertJsonMissingValidationErrors('container_id');

        $this->assertSame(2, $claimed->fresh()->amount);
    }

    #[Test]
    public function the_edit_form_saves_a_claimed_stack_whose_amount_it_leaves_alone(): void
    {
        [$user, $deckCard, $claimed, $binder] = $this->claimedStack(amount: 2);

        $this->actingAs($user)
            ->patch("/collection/cardstack/{$claimed->id}", $this->editForm($binder, amount: 2, condition: 'near_mint'))
            ->assertSessionHasNoErrors()
            ->assertRedirect("/containers/{$binder->id}");

        $this->assertSame(2, $claimed->fresh()->amount);
        $this->assertSame('near_mint', $claimed->fresh()->condition->value);
    }

    #[Test]
    public function the_edit_form_still_changes_an_unclaimed_stacks_amount(): void
    {
        [$user, $deckCard, , $binder] = $this->claimedStack(amount: 2);
        $free = $this->stack($user, $deckCard, $binder, 2, ['finish' => 2]);

        $this->actingAs($user)
            ->patch("/collection/cardstack/{$free->id}", $this->editForm($binder, amount: 3, finish: 'foil'))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $free->fresh()->amount);
    }

    #[Test]
    public function the_tables_plus_and_minus_leave_a_claimed_stack_alone(): void
    {
        [$user, , $claimed] = $this->claimedStack(amount: 2);

        foreach ([1, -1] as $delta) {
            $this->actingAs($user)
                ->patchJson("/api/collection/cardstack/{$claimed->id}/amount", ['delta' => $delta])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['delta' => trans('collection.errors.cannot_change_amount_of_claimed_stack', [], $user->preferredLocale())]);
        }

        $this->assertSame(2, $claimed->fresh()->amount);
    }

    #[Test]
    public function the_amount_lock_is_translated_for_every_locale(): void
    {
        foreach (['en', 'de'] as $locale) {
            $this->assertTrue(Lang::has('collection.errors.cannot_change_amount_of_claimed_stack', $locale, false), $locale);
            $this->assertTrue(Lang::has('collection.cards_mass_move_claimed_stayed', $locale, false), $locale);
        }
    }

    #[Test]
    public function moving_a_whole_container_leaves_its_claimed_stacks_behind_and_says_how_many(): void
    {
        [$user, $deckCard, $claimed, $binder] = $this->claimedStack(amount: 2);
        $loose = $this->stack($user, $deckCard, $binder, 3, ['finish' => 2]);
        $other = $this->stack($user, $deckCard, $binder, 1, ['language' => 'de']);
        $target = $this->container($user, 'Lands');
        $locale = $user->preferredLocale();

        $this->actingAs($user)
            ->from("/containers/{$binder->id}")
            ->patch("/containers/{$binder->id}/move-all", ['container_id' => $target->id])
            ->assertRedirect("/containers/{$binder->id}")
            ->assertSessionHas('type', 'warning')
            ->assertSessionHas('message', trans('collection.cards_mass_moved', ['number' => '4', 'source' => 'Binder', 'target' => 'Lands'], $locale)
                .' '.trans_choice('collection.cards_mass_move_claimed_stayed', 2, ['number' => '2', 'source' => 'Binder'], $locale));

        $this->assertSame($binder->id, $claimed->fresh()->container_id);
        $this->assertSame($target->id, $loose->fresh()->container_id);
        $this->assertSame($target->id, $other->fresh()->container_id);
    }

    #[Test]
    public function moving_a_container_without_claimed_stacks_moves_everything_to_unsorted(): void
    {
        [$user, $deckCard, , $binder] = $this->claimedStack(amount: 2);
        $source = $this->container($user, 'Bulk');
        $first = $this->stack($user, $deckCard, $source, 3);
        $second = $this->stack($user, $deckCard, $source, 1, ['finish' => 2]);

        $this->actingAs($user)
            ->from("/containers/{$source->id}")
            ->patch("/containers/{$source->id}/move-all", ['container_id' => null])
            ->assertSessionHas('type', 'success')
            ->assertSessionHas('message', trans('collection.cards_mass_moved', ['number' => '4', 'source' => 'Bulk', 'target' => trans('collection.unsorted', [], $user->preferredLocale())], $user->preferredLocale()));

        $this->assertNull($first->fresh()->container_id);
        $this->assertNull($second->fresh()->container_id);
        $this->assertSame(1, CardStack::query()->where('container_id', $binder->id)->count());
    }

    /**
     * A finished mode-C deck of a new user whose one Forest claims one stack
     * of `$amount` copies (the deck card wants exactly those) — in a binder,
     * or unsorted. With `$claimedFirst` false an unclaimed twin of one copy
     * is created before the claimed stack, so an import that matched every
     * stack would land on the claimed one (the last of its key).
     *
     * @return array{0: User, 1: DeckCard, 2: CardStack, 3: Container}
     */
    private function claimedStack(int $amount, bool $inContainer = true, bool $claimedFirst = true): array
    {
        $user = User::factory()->create(['collection_integration_enabled' => true]);
        $deck = $this->deck($user, 'Lands');
        $binder = $this->container($user, 'Binder');
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
            'code' => 'lea',
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
            'quantity' => $amount,
        ]);
        $container = $inContainer ? $binder : null;
        if (! $claimedFirst) {
            $this->stack($user, $deckCard, $container, 1);
        }
        $stack = $this->stack($user, $deckCard, $container, $amount);
        $deckCard->cardStacks()->attach($stack->id);

        return [$user, $deckCard, $stack, $binder];
    }

    /**
     * The add form's fields for more nonfoil English copies of the deck
     * card's printing — the claimed stack's attributes exactly.
     *
     * @return array<string, mixed>
     */
    private function addForm(DeckCard $deckCard, Container $container, int $amount): array
    {
        return [
            'default_card_id' => $deckCard->default_card_id,
            'amount' => $amount,
            'language' => 'en',
            'container_id' => $container->id,
            'condition' => '',
            'finish' => 'nonfoil',
        ];
    }

    /**
     * The edit form's fields: it submits every one, the container unchanged.
     *
     * @return array<string, mixed>
     */
    private function editForm(Container $container, int $amount, string $condition = '', string $finish = 'nonfoil'): array
    {
        return [
            'amount' => $amount,
            'language' => 'en',
            'container_id' => $container->id,
            'condition' => $condition,
            'finish' => $finish,
        ];
    }

    /**
     * Import `$count` unsorted nonfoil English copies of the deck card's
     * printing through the cantrip CSV format.
     *
     * @return array{imported: int, merged: int, skipped: int, skipped_rows: array<mixed>}
     */
    private function importCsv(User $user, DeckCard $deckCard, int $count): array
    {
        Storage::fake('tmp');
        $filename = Str::uuid().'.csv';
        Storage::disk('tmp')->put($filename, implode("\n", [
            'Scryfall ID,Count,Name,Edition,Condition,Language,Foil,Collector Number',
            "{$deckCard->default_card_id},{$count},Forest,lea,,en,nonfoil,294",
        ])."\n");

        return CsvImportService::import($user, $filename, ImportSource::Cantrip, null);
    }

    private function deckCard(Deck $deck, DeckCard $like, int $quantity): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $like->oracle_card_id,
            'default_card_id' => $like->default_card_id,
            'zone' => DeckZone::Main->value,
            'quantity' => $quantity,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stack(User $user, DeckCard $deckCard, ?Container $container, int $amount, array $attributes = []): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $deckCard->default_card_id,
            'container_id' => $container?->id,
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
            'type' => 'binder',
            'sort_order' => 1,
        ]);
    }
}
