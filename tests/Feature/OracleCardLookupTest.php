<?php

namespace Tests\Feature;

use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\OracleCardFace;
use App\Models\Set;
use App\Services\OracleCardLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The welcome page's Oracle card lookup at `/api/cards/lookup`.
 */
class OracleCardLookupTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Set> Sets by code, created on first use. */
    private array $sets = [];

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

        // The name ranking every card search shares orders by CHAR_LENGTH,
        // which MariaDB has and SQLite lacks.
        DB::connection()->getPdo()->sqliteCreateFunction('CHAR_LENGTH', fn ($value): int => mb_strlen((string) $value), 1);
    }

    #[Test]
    public function guests_find_oracle_cards_with_their_faces_and_first_printing(): void
    {
        $fable = $this->makeCard('Fable of the Mirror-Breaker // Reflection of Kiki-Jiki', 'R', [
            ['{2}{R}', 'Enchantment — Saga'],
            [null, 'Enchantment Creature — Goblin Shaman'],
        ]);
        $neo = $this->print($fable, 'neo', '2022-02-18', '141', 'expansion');
        $this->print($fable, 'prm', '2002-06-24', '98009', 'promo', digital: true);
        $this->print($fable, 'neo', '2022-02-18', '357', 'expansion');

        $response = $this->getJson('/api/cards/lookup?q=fable');

        $response->assertOk()->assertExactJson([
            'cards' => [[
                'id' => $fable->id,
                'name' => 'Fable of the Mirror-Breaker // Reflection of Kiki-Jiki',
                'color_identity' => 'R',
                'printing_id' => $neo->id,
                'faces' => [
                    ['type_line' => 'Enchantment — Saga', 'mana_cost' => '{2}{R}'],
                    ['type_line' => 'Enchantment Creature — Goblin Shaman', 'mana_cost' => null],
                ],
            ]],
            'truncated' => false,
        ]);
    }

    #[Test]
    public function exact_names_rank_before_prefixes_before_the_rest(): void
    {
        // Created in reverse so insertion order can't pass for ranking.
        foreach (['Soltari Ring', 'Sol Ring Prototype', 'Sol Ringleader', 'Sol Ring', 'Mox Ruby'] as $name) {
            $this->makeCard($name, null);
        }

        $names = array_column($this->getJson('/api/cards/lookup?q=sol ring')->json('cards'), 'name');

        // Exact, then the prefixes shortest first, then a name that only
        // contains both words. Mox Ruby matches neither.
        $this->assertSame(['Sol Ring', 'Sol Ringleader', 'Sol Ring Prototype', 'Soltari Ring'], $names);
    }

    #[Test]
    public function set_and_number_pick_one_printing_and_the_modal_shows_it(): void
    {
        $ragavan = $this->makeCard('Ragavan, Nimble Pilferer', 'R');
        $this->print($ragavan, 'mh2', '2021-06-18', '138', 'draft_innovation');
        $promo = $this->print($ragavan, 'pmh2', '2021-06-18', '138s', 'promo');
        $bolt = $this->makeCard('Lightning Bolt', 'R');
        $this->print($bolt, 'lea', '1993-08-05', '161', 'core');
        $this->print($bolt, 'mh2', '2021-06-18', '400', 'draft_innovation');

        $cards = $this->getJson('/api/cards/lookup?q=set:pmh2 number:138s')->json('cards');

        $this->assertSame([$ragavan->id], array_column($cards, 'id'));
        $this->assertSame($promo->id, $cards[0]['printing_id']);
    }

    #[Test]
    public function set_and_number_have_to_match_the_same_printing(): void
    {
        // Printed in MH2 (as #400) and as a #138 elsewhere — but no MH2 #138.
        $bolt = $this->makeCard('Lightning Bolt', 'R');
        $this->print($bolt, 'mh2', '2021-06-18', '400', 'draft_innovation');
        $this->print($bolt, 'lea', '1993-08-05', '138', 'core');

        $this->assertSame([], $this->getJson('/api/cards/lookup?q=set:mh2 number:138')->json('cards'));
    }

    #[Test]
    public function tokens_emblems_and_art_cards_are_left_out_but_planes_stay(): void
    {
        $card = $this->makeCard('Fabled Passage', null, [[null, 'Land']]);
        foreach (['art_series' => 'Card', 'token' => 'Token Creature — Fable', 'emblem' => 'Emblem', 'front_card' => 'Card'] as $layout => $typeLine) {
            $this->makeCard("Fabled {$layout}", null, [[null, $typeLine]], $layout);
        }
        $plane = $this->makeCard('Fabled Plane', null, [[null, 'Plane — Fable']], 'planar');

        $ids = array_column($this->getJson('/api/cards/lookup?q=fabled')->json('cards'), 'id');

        $this->assertEqualsCanonicalizing([$card->id, $plane->id], $ids);
    }

    #[Test]
    public function a_card_without_a_printing_still_shows_up(): void
    {
        $card = $this->makeCard('Unprinted Wonder', 'U');

        $cards = $this->getJson('/api/cards/lookup?q=unprinted')->json('cards');

        $this->assertSame([$card->id], array_column($cards, 'id'));
        $this->assertNull($cards[0]['printing_id']);
    }

    #[Test]
    public function a_long_result_is_cut_short_and_says_so(): void
    {
        foreach (range(1, OracleCardLookupService::RESULT_LIMIT + 1) as $i) {
            $this->makeCard("Dragon Whelp {$i}", 'R');
        }

        $response = $this->getJson('/api/cards/lookup?q=dragon');

        $this->assertCount(OracleCardLookupService::RESULT_LIMIT, $response->json('cards'));
        $this->assertTrue($response->json('truncated'));
    }

    #[Test]
    public function a_query_too_short_to_search_finds_nothing(): void
    {
        $this->makeCard('X', null);

        $this->getJson('/api/cards/lookup?q=x')
            ->assertOk()
            ->assertExactJson(['cards' => [], 'truncated' => false]);
    }

    #[Test]
    public function the_query_is_required(): void
    {
        $this->getJson('/api/cards/lookup')->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    /**
     * @param  list<array{0: string|null, 1: string}>  $faces  [mana cost, type line], front first.
     */
    private function makeCard(string $name, ?string $colorIdentity, array $faces = [['{1}', 'Artifact']], ?string $layout = null): OracleCard
    {
        // A query-builder insert: `front_card` is a layout the model's enum
        // cast does not know.
        $id = (string) Str::uuid();
        OracleCard::query()->insert([
            'id' => $id,
            'name' => $name,
            'searchable_name' => mb_strtolower(str_replace([',', '//'], ['', ' '], $name)),
            'collector_number' => '1',
            'layout' => $layout ?? (count($faces) > 1 ? 'transform' : 'normal'),
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => $colorIdentity,
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);
        $oracle = OracleCard::query()->findOrFail($id);
        foreach ($faces as $index => [$manaCost, $typeLine]) {
            OracleCardFace::create([
                'id' => (string) Str::uuid(),
                'oracle_card_id' => $oracle->id,
                'face_index' => $index,
                'name' => $name,
                'mana_cost' => $manaCost,
                'type_line' => $typeLine,
            ]);
        }

        return $oracle;
    }

    private function print(OracleCard $card, string $setCode, string $releasedAt, string $collectorNumber, string $setType, bool $digital = false): DefaultCard
    {
        $this->sets[$setCode] ??= Set::create([
            'id' => (string) Str::uuid(),
            'name' => strtoupper($setCode),
            'code' => $setCode,
            'released_at' => $releasedAt,
            'card_count' => 1,
            'set_type' => $setType,
            'scryfall_uri' => 'https://example.com/set',
            'path' => $setCode,
        ]);

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $card->name,
            'searchable_name' => $card->searchable_name,
            'collector_number' => $collectorNumber,
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'digital' => $digital,
            'rarity' => 'rare',
            'set_id' => $this->sets[$setCode]->id,
            'oracle_id' => $card->id,
        ]);
    }
}
