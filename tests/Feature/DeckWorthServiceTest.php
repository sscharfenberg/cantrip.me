<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\DeckWorthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for which deck zones {@see DeckWorthService} prices.
 *
 * Every zone that is part of the deck counts — mainboard, sideboard,
 * command zone, companion. The maybeboard is a scratch pile and does not.
 * The fixtures price the maybeboard card far above everything else, so a
 * total that wrongly includes it can't hide inside rounding.
 */
class DeckWorthServiceTest extends TestCase
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

    private function makeCard(string $name, float $priceEur): DefaultCard
    {
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

        $oracle = OracleCard::create([
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

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $oracle->name,
            'searchable_name' => $oracle->searchable_name,
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'price_eur' => $priceEur,
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    /**
     * A deck holding one priced card per row.
     *
     * @param  array<int, array{0: DeckZone, 1: int, 2: float}>  $rows  zone, quantity, unit price in EUR
     */
    private function makeDeck(User $user, array $rows): Deck
    {
        $deck = Deck::create([
            'user_id' => $user->id,
            'name' => 'Test Deck',
            'format' => CardFormat::Legacy->value,
        ]);

        foreach ($rows as $index => [$zone, $quantity, $price]) {
            $printing = $this->makeCard("Card {$index}", $price);
            DeckCard::create([
                'deck_id' => $deck->id,
                'oracle_card_id' => $printing->oracle_id,
                'default_card_id' => $printing->id,
                'zone' => $zone->value,
                'quantity' => $quantity,
            ]);
        }

        return $deck;
    }

    #[Test]
    public function the_maybeboard_does_not_count_toward_the_deck_worth(): void
    {
        $user = User::factory()->create(['currency' => 'eur']);
        $deck = $this->makeDeck($user, [
            [DeckZone::Main, 2, 1.50],
            [DeckZone::Side, 1, 4.00],
            [DeckZone::Command, 1, 10.00],
            [DeckZone::Companion, 1, 20.00],
            [DeckZone::Maybe, 1, 500.00],
        ]);

        $this->assertSame(37.0, DeckWorthService::totalForDeck($user, $deck));
    }

    #[Test]
    public function a_deck_with_only_maybeboard_cards_is_worth_nothing(): void
    {
        $user = User::factory()->create(['currency' => 'eur']);
        $deck = $this->makeDeck($user, [[DeckZone::Maybe, 4, 500.00]]);

        $this->assertSame(0.0, DeckWorthService::totalForDeck($user, $deck));
    }

    #[Test]
    public function the_deck_page_header_excludes_the_maybeboard(): void
    {
        $user = User::factory()->create(['currency' => 'eur']);
        $deck = $this->makeDeck($user, [
            [DeckZone::Main, 2, 1.50],
            [DeckZone::Maybe, 1, 500.00],
        ]);

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deck.total_worth', 3)
            );
    }

    #[Test]
    public function the_deck_list_excludes_the_maybeboard(): void
    {
        $user = User::factory()->create(['currency' => 'eur']);
        $this->makeDeck($user, [
            [DeckZone::Main, 2, 1.50],
            [DeckZone::Maybe, 1, 500.00],
        ]);

        $this->actingAs($user)
            ->get(route('decks'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('decksByFormat.'.CardFormat::Legacy->value.'.0.total_worth', 3)
            );
    }
}
