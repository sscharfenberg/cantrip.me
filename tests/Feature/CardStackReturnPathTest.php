<?php

namespace Tests\Feature;

use App\Models\CardStack;
use App\Models\Container;
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
 * Changing a card stack sends the user back to the page they changed it
 * from — a collection search with its filters, sort and page, or a
 * container — when that page passes itself as `return`. Without one, the
 * old targets apply: the stack's container, else the collection.
 */
class CardStackReturnPathTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH = '/collection?search=sol+ring&sort=name&dir=asc&page=1';

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
    public function the_edit_form_carries_the_page_it_was_opened_from(): void
    {
        [$user, $stack] = $this->stackInContainer();

        $this->actingAs($user)
            ->get("/collection/cardstack/{$stack->id}/edit?return=".urlencode(self::SEARCH))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('returnTo', self::SEARCH));

        $this->actingAs($user)
            ->get("/collection/cardstack/{$stack->id}/edit?return=".urlencode('//evil.example'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('returnTo', null));
    }

    #[Test]
    public function saving_returns_to_the_page_the_edit_was_opened_from(): void
    {
        [$user, $stack] = $this->stackInContainer();

        $this->actingAs($user)
            ->patch("/collection/cardstack/{$stack->id}", [...$this->formFields($stack), 'return' => self::SEARCH])
            ->assertRedirect(self::SEARCH);

        $this->assertSame(3, $stack->fresh()->amount);
    }

    #[Test]
    public function saving_without_a_page_to_return_to_goes_to_the_container(): void
    {
        [$user, $stack] = $this->stackInContainer();

        $this->actingAs($user)
            ->patch("/collection/cardstack/{$stack->id}", $this->formFields($stack))
            ->assertRedirect("/containers/{$stack->container_id}");

        $this->actingAs($user)
            ->patch("/collection/cardstack/{$stack->id}", [...$this->formFields($stack), 'return' => 'https://evil.example/'])
            ->assertRedirect("/containers/{$stack->container_id}");
    }

    #[Test]
    public function deleting_returns_to_the_page_it_came_from(): void
    {
        [$user, $stack] = $this->stackInContainer();

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}?return=".urlencode(self::SEARCH))
            ->assertRedirect(self::SEARCH);

        $this->assertNull($stack->fresh());
    }

    #[Test]
    public function deleting_without_a_page_to_return_to_goes_to_the_container(): void
    {
        [$user, $stack] = $this->stackInContainer();

        $this->actingAs($user)
            ->delete("/collection/cardstack/{$stack->id}")
            ->assertRedirect("/containers/{$stack->container_id}");
    }

    #[Test]
    public function deleting_several_returns_to_the_page_it_came_from(): void
    {
        [$user, $stack] = $this->stackInContainer();
        [, $other] = $this->stackInContainer($user);

        $this->actingAs($user)
            ->delete('/collection/cardstack/delete-selected?return='.urlencode(self::SEARCH), [
                'card_stack_ids' => [$stack->id, $other->id],
            ])
            ->assertRedirect(self::SEARCH);

        $this->assertSame(0, CardStack::query()->count());
    }

    #[Test]
    public function a_page_past_the_end_shows_the_last_page_instead(): void
    {
        [$user] = $this->stackInContainer();

        $this->actingAs($user)
            ->get('/collection?page=3')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.page', 1)
                ->count('table.rows', 1));
    }

    /**
     * A stack of a (new or given) user's, in one of their containers.
     *
     * @return array{0: User, 1: CardStack}
     */
    private function stackInContainer(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $container = Container::create([
            'user_id' => $user->id,
            'name' => 'Binder '.Str::random(4),
            'type' => 'binder',
            'sort_order' => 1,
        ]);
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
            'name' => 'Test Set '.Str::random(6),
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
            'amount' => 1,
            'finish' => 1,
            'language' => 'en',
        ]);

        return [$user, $stack];
    }

    /**
     * The edit form's fields for the stack, with the amount changed to 3.
     *
     * @return array<string, mixed>
     */
    private function formFields(CardStack $stack): array
    {
        return [
            'amount' => 3,
            'language' => 'en',
            'condition' => null,
            'finish' => 'nonfoil',
            'container_id' => $stack->container_id,
        ];
    }
}
