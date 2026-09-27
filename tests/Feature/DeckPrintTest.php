<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\ContainerVisibility;
use App\Enums\DeckCardRole;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DeckCategory;
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
 * The printable deck page (`/decks/{deck}/print`): same visibility rules
 * as the deck page, and a lean payload carrying exactly what the plain-text
 * list is built from.
 */
class DeckPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }
    }

    #[Test]
    public function owner_sees_the_payload_the_text_is_built_from(): void
    {
        $owner = User::factory()->create();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private, CardFormat::Commander);
        $deck->update(['description' => 'Counters everywhere.', 'bracket' => 3, 'state' => DeckState::Built->value]);
        $ramp = DeckCategory::create(['deck_id' => $deck->id, 'name' => 'Ramp']);

        $this->addRow($deck, 'Atraxa, Praetors\' Voice', 'cmr', '347', 'Legendary Creature — Phyrexian Angel Horror', zone: DeckZone::Command, role: DeckCardRole::Commander);
        $sol = $this->addRow($deck, 'Sol Ring', 'cmd', '263', 'Artifact', category: $ramp);
        $this->addRow($deck, 'Forest', 'one', '276', 'Basic Land — Forest', quantity: 30);

        $this->actingAs($owner)
            ->get('/decks/'.$deck->id.'/print')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Deck/DeckPrintPage')
                ->where('isOwner', true)
                ->where('deck.name', 'Test Deck')
                ->where('deck.description', 'Counters everywhere.')
                ->where('deck.format', 'commander')
                ->where('deck.state', 'built')
                ->where('deck.bracket', 3)
                ->where('deck.card_count', ['main' => 32, 'companion' => 0, 'side' => 0])
                ->where('commanders', [[
                    'name' => 'Atraxa, Praetors\' Voice',
                    'quantity' => 1,
                    'set_code' => 'cmr',
                    'collector_number' => '347',
                ]])
                ->where('companion', null)
                ->has('cards', 2)
                ->where('categories', [['id' => $ramp->id, 'name' => 'Ramp']])
                ->where('cards', fn ($cards) => collect($cards)->firstWhere('name', 'Sol Ring') === [
                    'name' => 'Sol Ring',
                    'quantity' => 1,
                    'set_code' => 'cmd',
                    'collector_number' => '263',
                    'id' => $sol->id,
                    'cmc' => 1,
                    'type_line' => 'Artifact',
                    'zone' => 'main',
                    'category_id' => $ramp->id,
                ])
            );
    }

    #[Test]
    public function primary_commander_comes_before_the_partner(): void
    {
        $owner = User::factory()->create();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private, CardFormat::Commander);
        $this->addRow($deck, 'Bruse Tarl, Boorish Herder', 'cmr', '237', 'Legendary Creature', zone: DeckZone::Command, role: DeckCardRole::Partner);
        $this->addRow($deck, 'Akiri, Line-Slinger', 'cmr', '236', 'Legendary Creature', zone: DeckZone::Command, role: DeckCardRole::Commander);

        $this->actingAs($owner)
            ->get('/decks/'.$deck->id.'/print')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commanders.0.name', 'Akiri, Line-Slinger')
                ->where('commanders.1.name', 'Bruse Tarl, Boorish Herder')
            );
    }

    #[Test]
    public function companion_and_sideboard_rows_ship_in_their_own_slots(): void
    {
        $owner = User::factory()->create();
        $deck = $this->makeDeck($owner, ContainerVisibility::Private, CardFormat::Legacy);
        $this->addRow($deck, 'Lurrus of the Dream-Den', 'iko', '226', 'Legendary Creature', zone: DeckZone::Companion, role: DeckCardRole::Companion);
        $this->addRow($deck, 'Pyroblast', 'ice', '213', 'Instant', zone: DeckZone::Side, quantity: 2);

        $this->actingAs($owner)
            ->get('/decks/'.$deck->id.'/print')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('companion.name', 'Lurrus of the Dream-Den')
                ->has('cards', 1)
                ->where('cards.0.zone', 'side')
                ->where('deck.card_count', ['main' => 0, 'companion' => 1, 'side' => 2])
                ->where('deck.max_sideboard_size', 15)
            );
    }

    #[Test]
    public function public_deck_is_printable_by_a_guest(): void
    {
        $deck = $this->makeDeck(User::factory()->create(), ContainerVisibility::Public, CardFormat::Legacy);

        $this->get('/decks/'.$deck->id.'/print')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Deck/DeckPrintPage')
                ->where('isOwner', false)
            );
    }

    #[Test]
    public function private_deck_returns_404_for_a_non_owner_and_a_guest(): void
    {
        $deck = $this->makeDeck(User::factory()->create(), ContainerVisibility::Private, CardFormat::Legacy);

        $this->actingAs(User::factory()->create())
            ->get('/decks/'.$deck->id.'/print')
            ->assertNotFound();

        auth()->logout();
        $this->get('/decks/'.$deck->id.'/print')->assertNotFound();
    }

    private function makeDeck(User $user, ContainerVisibility $visibility, CardFormat $format): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'Test Deck',
            'format' => $format->value,
            'visibility' => $visibility->value,
        ]);
    }

    private function addRow(
        Deck $deck,
        string $name,
        string $setCode,
        string $collectorNumber,
        string $typeLine,
        DeckZone $zone = DeckZone::Main,
        ?DeckCardRole $role = null,
        int $quantity = 1,
        ?DeckCategory $category = null,
    ): DeckCard {
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => 'C',
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);
        OracleCardFace::create([
            'id' => (string) Str::uuid(),
            'oracle_card_id' => $oracle->id,
            'face_index' => 0,
            'name' => $name,
            'type_line' => $typeLine,
        ]);
        $set = Set::firstOrCreate(
            ['code' => $setCode],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Test Set '.strtoupper($setCode),
                'released_at' => '2026-01-01',
                'card_count' => 1,
                'set_type' => 'expansion',
                'scryfall_uri' => 'https://example.com/set/'.$setCode,
                'path' => $setCode,
            ]
        );
        $printing = DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => $collectorNumber,
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);

        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $printing->id,
            'zone' => $zone->value,
            'role' => $role?->value,
            'quantity' => $quantity,
            'category_id' => $category?->id,
        ]);
    }
}
