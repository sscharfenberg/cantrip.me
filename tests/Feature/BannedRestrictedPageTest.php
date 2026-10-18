<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckState;
use App\Models\Deck;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\OracleCardFace;
use App\Models\OracleCardLegality;
use App\Models\Set;
use App\Models\User;
use App\Services\BannedRestrictedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public banned & restricted page at `/b+r`: one entry per format,
 * most decks first, each list grouped by colour and sorted by name.
 */
class BannedRestrictedPageTest extends TestCase
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
    public function guests_see_every_format_with_its_deck_count(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([CardFormat::Commander, CardFormat::Commander, CardFormat::Legacy] as $format) {
            $this->makeDeck($user, $format);
        }
        // Every user's decks count, not only the viewer's — there is no viewer.
        $this->makeDeck($other, CardFormat::Commander);

        $props = $this->pageProps();

        $decks = array_column($props['formats'], 'decks', 'format');
        $this->assertSame(array_map(fn (CardFormat $format): string => $format->value, CardFormat::cases()), array_keys($decks));
        $this->assertSame(3, $decks['commander']);
        $this->assertSame(1, $decks['legacy']);
        $this->assertSame(0, $decks['vintage']);
    }

    #[Test]
    public function lists_are_grouped_by_colour_then_sorted_by_name(): void
    {
        $land = $this->makeCard('Dryad Arbor', 'normal', [['', 'Land Creature — Forest Dryad', 'G']]);
        $artifact = $this->makeCard('Sol Ring', 'normal', [['{1}', 'Artifact', null]]);
        $split = $this->makeCard('Fire // Ice', 'split', [['{1}{R}', 'Instant', null], ['{1}{U}', 'Instant', null]]);
        $zealot = $this->makeCard('Zealous Conscripts', 'normal', [['{4}{R}', 'Creature — Human Warrior', 'R']]);
        $blue = $this->makeCard('Brainstorm', 'normal', [['{U}', 'Instant', 'U']]);
        $aether = $this->makeCard('Æther Vial', 'normal', [['{R}', 'Instant', 'R']]);
        $white = $this->makeCard('Balance', 'normal', [['{1}{W}', 'Sorcery', 'W']]);

        foreach ([$land, $artifact, $split, $zealot, $blue, $aether, $white] as $card) {
            $this->ban($card, CardFormat::Legacy);
        }

        $legacy = $this->format($this->pageProps(), 'legacy');

        $this->assertSame([
            ['group' => 'W', 'cards' => [$white->id]],
            ['group' => 'U', 'cards' => [$blue->id]],
            // searchable_name puts Æther Vial under A, before Zealous Conscripts.
            ['group' => 'R', 'cards' => [$aether->id, $zealot->id]],
            ['group' => 'multicolor', 'cards' => [$split->id]],
            ['group' => 'colorless', 'cards' => [$artifact->id]],
            ['group' => 'land', 'cards' => [$land->id]],
        ], $legacy['banned']['groups']);
        $this->assertSame([], $legacy['banned']['categories']);
        $this->assertSame(['groups' => [], 'categories' => []], $legacy['restricted']);
    }

    #[Test]
    public function restricted_cards_get_their_own_list_and_shared_cards_ship_once(): void
    {
        $lotus = $this->makeCard('Black Lotus', 'normal', [['{0}', 'Artifact', null]]);
        $ritual = $this->makeCard('Dark Ritual', 'normal', [['{B}', 'Instant', 'B']]);
        $this->ban($lotus, CardFormat::Legacy);
        $this->ban($lotus, CardFormat::Vintage, 'restricted');
        $this->ban($ritual, CardFormat::Vintage, 'legal');

        $props = $this->pageProps();
        $vintage = $this->format($props, 'vintage');

        $this->assertSame(['groups' => [], 'categories' => []], $vintage['banned']);
        $this->assertSame([['group' => 'colorless', 'cards' => [$lotus->id]]], $vintage['restricted']['groups']);
        $this->assertSame([['group' => 'colorless', 'cards' => [$lotus->id]]], $this->format($props, 'legacy')['banned']['groups']);
        $this->assertSame([$lotus->id], array_keys($props['cards']));
        $this->assertSame(['{0}'], $props['cards'][$lotus->id]['mana_cost']);
    }

    #[Test]
    public function formats_the_app_does_not_know_are_left_out(): void
    {
        $card = $this->makeCard('Mind Twist', 'normal', [['{X}{B}', 'Sorcery', 'B']]);
        DB::table('legalities')->insert(['oracle_card_id' => $card->id, 'format' => 'tlr', 'legality' => 'banned']);

        $props = $this->pageProps();

        $this->assertNotContains('tlr', array_column($props['formats'], 'format'));
        $this->assertSame([], $props['cards']);
    }

    #[Test]
    public function each_card_is_pictured_by_its_newest_printing(): void
    {
        $card = $this->makeCard('Channel', 'normal', [['{G}{G}', 'Sorcery', 'G']], printings: ['1993-08-05', '2026-01-01', '2004-01-01']);
        $this->ban($card, CardFormat::Commander);

        $printing = $this->pageProps()['cards'][$card->id]['printing'];

        $newest = DefaultCard::query()
            ->where('oracle_id', $card->id)
            ->where('card_image_0', 'like', '%2026-01-01%')
            ->firstOrFail();
        $this->assertSame(['id' => $newest->id, 'image' => $newest->card_image_0], $printing);
    }

    #[Test]
    public function the_lists_are_cached_until_scryfall_cache_rebuilds_them(): void
    {
        $first = $this->makeCard('Brainstorm', 'normal', [['{U}', 'Instant', 'U']]);
        $this->ban($first, CardFormat::Pauper);
        $this->pageProps();

        $second = $this->makeCard('Gush', 'normal', [['{4}{U}', 'Instant', 'U']]);
        $this->ban($second, CardFormat::Pauper);
        $this->assertSame([$first->id], $this->format($this->pageProps(), 'pauper')['banned']['groups'][0]['cards']);

        $this->artisan('scryfall:cache')->assertSuccessful();

        $this->assertSame([$first->id, $second->id], $this->format($this->pageProps(), 'pauper')['banned']['groups'][0]['cards']);
        $this->assertTrue(Cache::has(BannedRestrictedService::CACHE_KEY));
    }

    #[Test]
    public function blanket_bans_are_listed_by_category_not_by_colour(): void
    {
        $conspiracy = $this->makeCard('Backup Plan', 'normal', [[null, 'Conspiracy', null, 'Hidden agenda (Start the game with this conspiracy face down.)']]);
        $ante = $this->makeCard('Darkpact', 'normal', [['{B}{B}{B}', 'Sorcery', 'B', "Remove Darkpact from your deck before playing if you're not playing for ante."]]);
        $offensive = $this->makeCard('Crusade', 'normal', [['{W}{W}', 'Enchantment', 'W', 'White creatures get +1/+1.']]);
        $attraction = $this->makeCard('Balloon Stand', 'normal', [[null, 'Artifact — Attraction', null, 'Visit — Create a 1/1 token.']]);
        $sticker = $this->makeCard('Aerialephant', 'normal', [['{3}{W}', 'Creature — Elephant', 'W', 'When this creature enters, you may put a name sticker on it.']]);
        $orb = $this->makeCard('Chaos Orb', 'normal', [['{2}', 'Artifact', null, 'Flip Chaos Orb onto the battlefield from a height of at least one foot.']]);

        foreach ([$sticker, $attraction, $offensive, $ante, $conspiracy, $orb] as $card) {
            $this->ban($card, CardFormat::Vintage);
        }

        $banned = $this->format($this->pageProps(), 'vintage')['banned'];

        $this->assertSame([['group' => 'colorless', 'cards' => [$orb->id]]], $banned['groups']);
        $this->assertSame([
            ['category' => 'conspiracy', 'cards' => [$conspiracy->id]],
            ['category' => 'ante', 'cards' => [$ante->id]],
            ['category' => 'offensive', 'cards' => [$offensive->id]],
            // Sorted by name inside the category, whatever their colours.
            ['category' => 'sticker', 'cards' => [$sticker->id, $attraction->id]],
        ], $banned['categories']);
    }

    #[Test]
    public function an_empty_dataset_is_not_cached(): void
    {
        $this->assertSame([], $this->pageProps()['cards']);
        $this->assertFalse(Cache::has(BannedRestrictedService::CACHE_KEY));

        $card = $this->makeCard('Mind Twist', 'normal', [['{X}{B}', 'Sorcery', 'B']]);
        $this->ban($card, CardFormat::Legacy);

        $this->assertSame([$card->id], array_keys($this->pageProps()['cards']));
    }

    /**
     * Fetch `/b+r` as a guest and return its Inertia props.
     *
     * @return array<string, mixed>
     */
    private function pageProps(): array
    {
        $props = [];
        $this->get('/b+r')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $page->component('Guest/BannedRestricted');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array{format: string, banned: list<array{group: string, cards: list<string>}>, restricted: list<array{group: string, cards: list<string>}>}
     */
    private function format(array $props, string $format): array
    {
        return collect($props['formats'])->firstWhere('format', $format);
    }

    private function makeDeck(User $user, CardFormat $format): void
    {
        Deck::create([
            'user_id' => $user->id,
            'name' => 'Deck '.Str::random(6),
            'format' => $format->value,
            'state' => DeckState::Built->value,
            'collection_mode' => 'A',
        ]);
    }

    /**
     * @param  list<array{0: string|null, 1: string, 2: string|null, 3?: string}>  $faces  [mana cost, type line, colours, rules text], front first.
     * @param  list<string>  $printings  One printing per release date.
     */
    private function makeCard(string $name, string $layout, array $faces, array $printings = ['2026-01-01']): OracleCard
    {
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => str_replace('æ', 'ae', mb_strtolower($name)),
            'collector_number' => '1',
            'layout' => $layout,
            'lang' => 'en',
            'cmc' => 1,
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);
        foreach ($faces as $index => $face) {
            [$manaCost, $typeLine, $colors] = $face;
            OracleCardFace::create([
                'id' => (string) Str::uuid(),
                'oracle_card_id' => $oracle->id,
                'face_index' => $index,
                'name' => $name,
                'mana_cost' => $manaCost,
                'type_line' => $typeLine,
                'colors' => $colors,
                'oracle_text' => $face[3] ?? null,
            ]);
        }
        foreach ($printings as $releasedAt) {
            $set = Set::create([
                'id' => (string) Str::uuid(),
                'name' => 'Test Set '.Str::random(6),
                'code' => Str::lower(Str::random(3)),
                'released_at' => $releasedAt,
                'card_count' => 1,
                'set_type' => 'expansion',
                'scryfall_uri' => 'https://example.com/set',
                'path' => 'tst',
            ]);
            DefaultCard::create([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'searchable_name' => mb_strtolower($name),
                'collector_number' => '1',
                'layout' => $layout,
                'lang' => 'en',
                'card_image_0' => "/card-images/{$releasedAt}.webp",
                'finishes' => 1,
                'games' => 1,
                'rarity' => 'rare',
                'set_id' => $set->id,
                'oracle_id' => $oracle->id,
            ]);
        }

        return $oracle;
    }

    private function ban(OracleCard $card, CardFormat $format, string $legality = 'banned'): void
    {
        OracleCardLegality::create([
            'oracle_card_id' => $card->id,
            'format' => $format->value,
            'legality' => $legality,
        ]);
    }
}
