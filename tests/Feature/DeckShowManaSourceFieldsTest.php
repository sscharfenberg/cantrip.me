<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Http\Controllers\Decks\DecksController;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\OracleCardFace;
use App\Models\Set;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The deck page's colour-source weighting reads three per-card fields
 * {@see DecksController::show} derives from the oracle card:
 * `land_search_pattern`, `is_mdfc_land` and `mana_source`. These pin
 * that each one reaches the payload.
 */
class DeckShowManaSourceFieldsTest extends TestCase
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
    public function the_deck_page_ships_what_each_card_counts_as_a_colour_source(): void
    {
        $user = User::factory()->create();
        $deck = Deck::create([
            'user_id' => $user->id,
            'name' => 'Ramp',
            'format' => CardFormat::Legacy->value,
            'state' => DeckState::Built->value,
            'collection_mode' => 'A',
        ]);

        $this->addCard($deck, 'Three Visits', 'normal', null, ['Sorcery'], ['Search your library for a Forest card, put it onto the battlefield, then shuffle.'], 'typed:G');
        $this->addCard($deck, 'Bala Ged Recovery // Bala Ged Sanctuary', 'modal_dfc', 'G', ['Sorcery', 'Land'], ['Return target card from your graveyard to your hand.', 'As this land enters, you may pay 3 life. If you don\'t, it enters tapped.']);
        $this->addCard($deck, 'Llanowar Elves', 'normal', 'G', ['Creature — Elf Druid'], ['{T}: Add {G}.']);

        $cards = [];
        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(function (AssertableInertia $page) use (&$cards): void {
                $cards = collect($page->toArray()['props']['cards'])->keyBy('name');
            });

        $this->assertSame('typed:G', $cards['Three Visits']['land_search_pattern']);
        $this->assertFalse($cards['Three Visits']['is_mdfc_land']);
        $this->assertNull($cards['Three Visits']['mana_source']);

        $this->assertTrue($cards['Bala Ged Recovery // Bala Ged Sanctuary']['is_mdfc_land']);

        $this->assertSame('ability', $cards['Llanowar Elves']['mana_source']);
        $this->assertNull($cards['Llanowar Elves']['land_search_pattern']);
    }

    /**
     * @param  list<string>  $typeLines  One per face, front first.
     * @param  list<string>  $oracleTexts  One per face, front first.
     */
    private function addCard(
        Deck $deck,
        string $name,
        string $layout,
        ?string $producedMana,
        array $typeLines,
        array $oracleTexts,
        ?string $landSearchPattern = null,
    ): void {
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => '1',
            'layout' => $layout,
            'lang' => 'en',
            'cmc' => 2,
            'color_identity' => 'G',
            'produced_mana' => $producedMana,
            'land_search_pattern' => $landSearchPattern,
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);
        foreach ($typeLines as $index => $typeLine) {
            OracleCardFace::create([
                'id' => (string) Str::uuid(),
                'oracle_card_id' => $oracle->id,
                'face_index' => $index,
                'name' => $name,
                'type_line' => $typeLine,
                'oracle_text' => $oracleTexts[$index],
            ]);
        }
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
        $printing = DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => '1',
            'layout' => $layout,
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
        DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $printing->id,
            'zone' => DeckZone::Main->value,
            'quantity' => 1,
        ]);
    }
}
