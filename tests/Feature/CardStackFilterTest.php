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
use App\Services\CardStackFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The filter section of the collection and container data tables
 * ({@see CardStackFilterService}).
 *
 * One fixture of seven stacks, chosen so that every filter splits it
 * differently — a filter that silently did nothing, or matched on the wrong
 * column, returns a different set of names than the one asserted:
 *
 * | card            | identity | type       | rarity   | set | lang | cond      | finish  | €            | container | claim          |
 * |-----------------|----------|------------|----------|-----|------|-----------|---------|--------------|-----------|----------------|
 * | Lightning Bolt  | R        | Instant    | common   | AAA | en   | near_mint | nonfoil | 0.50         | Binder    | Burn           |
 * | Counterspell    | U        | Instant    | uncommon | BBB | de   | played    | foil    | 0.30 / f 8   | Binder    | Control        |
 * | Llanowar Elves  | G        | Creature   | common   | AAA | en   | near_mint | nonfoil | 0.20         | —         |                |
 * | Vindicate       | WB       | Sorcery    | rare     | BBB | en   | near_mint | nonfoil | 3 ($30)      | Binder    |                |
 * | Lightning Helix | RW       | Instant    | uncommon | AAA | en   | near_mint | nonfoil | 1            | Binder    | Burn           |
 * | Sol Ring        | —        | Artifact   | uncommon | CCC | en   | near_mint | nonfoil | 2, but proxy | Deckbox   |                |
 * | Gaea's Cradle   | —        | Land, RL   | rare     | CCC | en   | excellent | nonfoil | 900          | Deckbox   |                |
 *
 * The Burn deck also uses the Deckbox as its deckbox, so Sol Ring and Gaea's
 * Cradle are implicitly held by it — and must still not count as claimed.
 */
class CardStackFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Container $binder;

    private Container $deckbox;

    private Deck $burn;

    private Deck $control;

    /** @var array<string, Set> */
    private array $sets = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }

        $this->user = User::factory()->create(['currency' => 'eur']);
        $this->binder = $this->makeContainer('Binder');
        $this->deckbox = $this->makeContainer('Deckbox');
        $this->burn = $this->makeDeck('Burn', $this->deckbox);
        $this->control = $this->makeDeck('Control');
        $this->makeDeck('Unclaiming');

        $bolt = $this->makeStack('Lightning Bolt', 'R', 'Instant', 'common', 'AAA', ['price_eur' => 0.5], container: $this->binder);
        $counterspell = $this->makeStack('Counterspell', 'U', 'Instant', 'uncommon', 'BBB', ['price_eur' => 0.3, 'price_eur_foil' => 8], container: $this->binder, stack: ['language' => 'de', 'condition' => 'played', 'finish' => 2]);
        $this->makeStack('Llanowar Elves', 'G', 'Creature — Elf Druid', 'common', 'AAA', ['price_eur' => 0.2]);
        $this->makeStack('Vindicate', 'WB', 'Sorcery', 'rare', 'BBB', ['price_eur' => 3, 'price_usd' => 30], container: $this->binder);
        $helix = $this->makeStack('Lightning Helix', 'RW', 'Instant', 'uncommon', 'AAA', ['price_eur' => 1], container: $this->binder);
        $this->makeStack('Sol Ring', null, 'Artifact', 'uncommon', 'CCC', ['price_eur' => 2], container: $this->deckbox, stack: ['proxy' => true]);
        $this->makeStack("Gaea's Cradle", null, 'Legendary Land', 'rare', 'CCC', ['price_eur' => 900], container: $this->deckbox, stack: ['condition' => 'excellent'], reserved: true);

        $this->claim($this->burn, $bolt);
        $this->claim($this->burn, $helix);
        $this->claim($this->control, $counterspell);
    }

    #[Test]
    public function without_filters_every_stack_is_listed_and_nothing_is_echoed(): void
    {
        $response = $this->collection();

        $this->assertCount(7, $this->rowNames($response));
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('table.filters', null));
    }

    #[Test]
    public function price_bounds_compare_the_per_copy_price_the_table_shows(): void
    {
        // Counterspell is foil: 8 €, although its nonfoil price is 0.30 €.
        // Sol Ring is a proxy: 0 €, although the printing costs 2 €.
        $this->assertSame(
            ['Counterspell', "Gaea's Cradle", 'Lightning Helix', 'Vindicate'],
            $this->rowNames($this->collection(['min_price' => '1'])),
        );
        $this->assertSame(
            ['Lightning Bolt', 'Lightning Helix', 'Llanowar Elves', 'Sol Ring'],
            $this->rowNames($this->collection(['max_price' => '1'])),
        );
        $this->assertSame(
            ['Lightning Helix', 'Vindicate'],
            $this->rowNames($this->collection(['min_price' => '1', 'max_price' => '5'])),
        );
    }

    #[Test]
    public function price_bounds_use_the_users_currency(): void
    {
        $this->user->update(['currency' => 'usd']);

        // Only Vindicate has a dollar price in the fixture.
        $this->assertSame(['Vindicate'], $this->rowNames($this->collection(['min_price' => '10'])));
    }

    #[Test]
    public function the_proxy_filter_keeps_only_or_drops_proxies(): void
    {
        $this->assertSame(['Sol Ring'], $this->rowNames($this->collection(['proxy' => 'only'])));

        $withoutProxies = $this->rowNames($this->collection(['proxy' => 'none']));
        $this->assertCount(6, $withoutProxies);
        $this->assertNotContains('Sol Ring', $withoutProxies);
    }

    #[Test]
    public function stack_attributes_filter_by_their_own_column(): void
    {
        $this->assertSame(['Counterspell'], $this->rowNames($this->collection(['lang' => 'de'])));
        $this->assertSame(["Gaea's Cradle"], $this->rowNames($this->collection(['condition' => 'excellent'])));
        $this->assertSame(['Counterspell'], $this->rowNames($this->collection(['finish' => 'foil'])));
        $this->assertSame(["Gaea's Cradle", 'Vindicate'], $this->rowNames($this->collection(['rarity' => 'rare'])));
        $this->assertSame(
            ['Lightning Bolt', 'Lightning Helix', 'Llanowar Elves'],
            $this->rowNames($this->collection(['set' => $this->sets['AAA']->code])),
        );
    }

    #[Test]
    public function card_type_matches_the_type_line(): void
    {
        $this->assertSame(
            ['Counterspell', 'Lightning Bolt', 'Lightning Helix'],
            $this->rowNames($this->collection(['type' => 'Instant'])),
        );
        $this->assertSame(['Llanowar Elves'], $this->rowNames($this->collection(['type' => 'Creature'])));
        $this->assertSame(["Gaea's Cradle"], $this->rowNames($this->collection(['type' => 'Land'])));
    }

    #[Test]
    public function color_identity_supports_contains_exactly_and_at_most(): void
    {
        $this->assertSame(
            ['Lightning Bolt', 'Lightning Helix'],
            $this->rowNames($this->collection(['colors' => 'R'])),
        );
        $this->assertSame(
            ['Lightning Bolt'],
            $this->rowNames($this->collection(['colors' => 'R', 'color_mode' => 'exact'])),
        );
        // At most red and white: colorless cards fit too, Vindicate's black does not.
        $this->assertSame(
            ["Gaea's Cradle", 'Lightning Bolt', 'Lightning Helix', 'Sol Ring'],
            $this->rowNames($this->collection(['colors' => 'RW', 'color_mode' => 'within'])),
        );
        $this->assertSame(
            ["Gaea's Cradle", 'Sol Ring'],
            $this->rowNames($this->collection(['colors' => 'C'])),
        );
    }

    #[Test]
    public function the_reserved_list_filter_keeps_reserved_cards_only(): void
    {
        $this->assertSame(["Gaea's Cradle"], $this->rowNames($this->collection(['reserved' => '1'])));
    }

    #[Test]
    public function the_container_filter_takes_a_container_or_unsorted(): void
    {
        $this->assertSame(
            ["Gaea's Cradle", 'Sol Ring'],
            $this->rowNames($this->collection(['container' => $this->deckbox->id])),
        );
        $this->assertSame(['Llanowar Elves'], $this->rowNames($this->collection(['container' => 'unsorted'])));
    }

    #[Test]
    public function the_claim_filter_counts_explicit_claims_only(): void
    {
        // Sol Ring and Gaea's Cradle sit in the Burn deck's deckbox, which is
        // an implicit hold — not a claim.
        $this->assertSame(
            ['Counterspell', 'Lightning Bolt', 'Lightning Helix'],
            $this->rowNames($this->collection(['claimed' => 'any'])),
        );
        $this->assertSame(
            ["Gaea's Cradle", 'Llanowar Elves', 'Sol Ring', 'Vindicate'],
            $this->rowNames($this->collection(['claimed' => 'none'])),
        );
        $this->assertSame(
            ['Lightning Bolt', 'Lightning Helix'],
            $this->rowNames($this->collection(['claimed' => $this->burn->id])),
        );
    }

    #[Test]
    public function filters_combine(): void
    {
        $this->assertSame(
            ['Lightning Helix'],
            $this->rowNames($this->collection(['rarity' => 'uncommon', 'type' => 'Instant', 'lang' => 'en'])),
        );
    }

    #[Test]
    public function invalid_values_are_dropped_instead_of_rejected(): void
    {
        $response = $this->collection([
            'min_price' => 'abc',
            'max_price' => '-1',
            'proxy' => 'maybe',
            'lang' => 'xx',
            'condition' => 'mangled',
            'finish' => 'shiny',
            'rarity' => 'epic',
            'set' => 'a',
            'type' => 'Goblin',
            'colors' => 'XYZ',
            'reserved' => 'yes',
            'container' => 'nowhere',
            'claimed' => 'nobody',
        ]);

        $response->assertOk();
        $this->assertCount(7, $this->rowNames($response));
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('table.filters', null));
    }

    #[Test]
    public function an_array_parameter_counts_as_absent(): void
    {
        $response = $this->actingAs($this->user)->get('/collection?lang[]=de');

        $this->assertCount(7, $this->rowNames($response));
    }

    #[Test]
    public function applied_filters_are_echoed_normalized(): void
    {
        $this->collection(['min_price' => '1,50', 'set' => 'AAA', 'colors' => 'gw', 'color_mode' => 'exact'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.filters', [
                    'min_price' => '1.5',
                    'set' => 'aaa',
                    'colors' => 'WG',
                    'color_mode' => 'exact',
                ]));
    }

    #[Test]
    public function colorless_takes_no_color_mode(): void
    {
        $this->collection(['colors' => 'C', 'color_mode' => 'exact'])
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.filters', ['colors' => 'C']));
    }

    #[Test]
    public function the_statistics_still_describe_the_whole_collection(): void
    {
        $this->collection(['lang' => 'de'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.total', 1)
                ->where('stats.totalCards', 7));
    }

    #[Test]
    public function the_collection_ships_its_filter_options(): void
    {
        $this->collection()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filterOptions.languages', ['de', 'en'])
            ->where('filterOptions.rarities', ['common', 'uncommon', 'rare'])
            ->where('filterOptions.sets', collect(['AAA', 'BBB', 'CCC'])->map(fn (string $name) => [
                'code' => $this->sets[$name]->code,
                'name' => $name,
                'path' => 'set.svg',
            ])->all())
            ->where('filterOptions.containers', [
                ['id' => $this->binder->id, 'name' => 'Binder'],
                ['id' => $this->deckbox->id, 'name' => 'Deckbox'],
            ])
            // Only decks that explicitly claim something — not "Unclaiming".
            ->where('filterOptions.decks', [
                ['id' => $this->burn->id, 'name' => 'Burn'],
                ['id' => $this->control->id, 'name' => 'Control'],
            ]));
    }

    #[Test]
    public function the_container_page_filters_within_the_container(): void
    {
        $response = $this->actingAs($this->user)->get("/containers/{$this->binder->id}?claimed=any&rarity=uncommon");

        $this->assertSame(['Counterspell', 'Lightning Helix'], $this->rowNames($response));
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('filterOptions.containers')
            ->where('filterOptions.decks', [
                ['id' => $this->burn->id, 'name' => 'Burn'],
                ['id' => $this->control->id, 'name' => 'Control'],
            ]));
    }

    #[Test]
    public function the_container_page_takes_no_container_filter(): void
    {
        $response = $this->actingAs($this->user)->get("/containers/{$this->binder->id}?container=unsorted");

        $this->assertCount(4, $this->rowNames($response));
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('table.filters', null));
    }

    #[Test]
    public function a_visitor_can_neither_filter_by_claims_nor_see_the_decks(): void
    {
        $this->binder->update(['visibility' => 'public']);
        $visitor = User::factory()->create();

        $response = $this->actingAs($visitor)->get("/containers/{$this->binder->id}?claimed=any");

        $this->assertCount(4, $this->rowNames($response));
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('table.filters', null)
            ->missing('filterOptions.decks'));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function collection(array $query = []): TestResponse
    {
        return $this->actingAs($this->user)->get('/collection?'.http_build_query($query));
    }

    /**
     * The card names of the rows on the page, sorted, so an assertion states
     * the set of rows rather than the table's sort order.
     *
     * @return array<int, string>
     */
    private function rowNames(TestResponse $response): array
    {
        $names = [];
        $response->assertInertia(function (AssertableInertia $page) use (&$names): void {
            $names = collect($page->toArray()['props']['table']['rows'])->pluck('name')->sort()->values()->all();
        });

        return $names;
    }

    private function makeContainer(string $name): Container
    {
        return Container::create([
            'user_id' => $this->user->id,
            'name' => $name,
            'type' => 'binder',
            'sort_order' => 1,
        ]);
    }

    private function makeDeck(string $name, ?Container $deckbox = null): Deck
    {
        return Deck::create([
            'user_id' => $this->user->id,
            'name' => $name,
            'format' => CardFormat::Legacy->value,
            'state' => DeckState::Built->value,
            'container_id' => $deckbox?->id,
        ]);
    }

    /**
     * @param  array<string, float|int>  $prices
     * @param  array<string, mixed>  $stack
     */
    private function makeStack(
        string $name,
        ?string $colorIdentity,
        string $typeLine,
        string $rarity,
        string $setName,
        array $prices,
        ?Container $container = null,
        array $stack = [],
        bool $reserved = false,
    ): CardStack {
        $this->sets[$setName] ??= Set::create([
            'id' => (string) Str::uuid(),
            'name' => $setName,
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'set_type' => 'expansion',
            'scryfall_uri' => 'https://example.com/set',
            'path' => 'set.svg',
        ]);

        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => Str::lower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => $colorIdentity,
            'type_line' => $typeLine,
            'reserved' => $reserved,
            'scryfall_uri' => 'https://example.com/card',
        ]);

        $printing = DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => Str::lower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 3,
            'games' => 1,
            'rarity' => $rarity,
            'set_id' => $this->sets[$setName]->id,
            'oracle_id' => $oracle->id,
            ...$prices,
        ]);

        return CardStack::create([
            'user_id' => $this->user->id,
            'default_card_id' => $printing->id,
            'container_id' => $container?->id,
            'amount' => 1,
            'condition' => 'near_mint',
            'finish' => 1,
            'language' => 'en',
            ...$stack,
        ]);
    }

    private function claim(Deck $deck, CardStack $stack): void
    {
        $printing = DefaultCard::findOrFail($stack->default_card_id);

        DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $printing->oracle_id,
            'default_card_id' => $printing->id,
            'zone' => DeckZone::Main->value,
            'quantity' => 1,
        ])->cardStacks()->attach($stack->id);
    }
}
