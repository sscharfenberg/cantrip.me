<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckZone;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\DeckCardCopiesService;
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
 *
 * Each copy is priced by whoever owns its finish and proxy flag
 * ({@see DeckCardCopiesService}): a claimed copy as its
 * stack, an unclaimed one as the deck card plans it. Nonfoil and foil
 * prices sit far apart (1 vs 100) so a copy priced by the wrong owner
 * moves the total by whole euros.
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

    private function makeCard(string $name, float $priceEur, ?float $foilEur = null, int $finishes = 1): DefaultCard
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
            'finishes' => $finishes,
            'games' => 1,
            'rarity' => 'common',
            'price_eur' => $priceEur,
            'price_eur_foil' => $foilEur,
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

    #[Test]
    public function an_unclaimed_foil_is_priced_as_a_foil(): void
    {
        $user = User::factory()->create(['currency' => 'eur']);
        [$deck, $card] = $this->deckWith($user, 2, ['finish' => Finish::Foil]);

        $this->assertSame(200.0, DeckWorthService::totalForDeck($user, $deck));
        $this->assertSame(2, $card->quantity);
    }

    #[Test]
    public function an_unclaimed_proxy_is_worth_nothing(): void
    {
        $user = User::factory()->create(['currency' => 'eur']);
        [$deck] = $this->deckWith($user, 3, ['finish' => Finish::Foil, 'proxy' => true]);

        $this->assertSame(0.0, DeckWorthService::totalForDeck($user, $deck));
    }

    #[Test]
    public function a_claimed_copy_is_priced_as_its_stack_not_the_plan(): void
    {
        $user = User::factory()->create(['currency' => 'eur']);
        [$deck, $card] = $this->deckWith($user, 1);
        $card->cardStacks()->attach($this->stackFor($user, $card, 1, Finish::Foil)->id);

        $this->assertSame(100.0, DeckWorthService::totalForDeck($user, $deck));
    }

    #[Test]
    public function a_partly_claimed_card_prices_each_copy_by_its_owner(): void
    {
        $user = User::factory()->create(['currency' => 'eur']);
        [$deck, $card] = $this->deckWith($user, 4, ['finish' => Finish::Foil]);
        $card->cardStacks()->attach($this->stackFor($user, $card, 1, Finish::Nonfoil)->id);
        $card->cardStacks()->attach($this->stackFor($user, $card, 1, Finish::Foil, proxy: true)->id);

        // 1 claimed nonfoil (1) + 1 claimed proxy (0) + 2 planned foil (200).
        $this->assertSame(201.0, DeckWorthService::totalForDeck($user, $deck));
    }

    #[Test]
    public function more_claimed_copies_than_the_card_has_count_their_average_for_its_quantity(): void
    {
        $user = User::factory()->create(['currency' => 'eur']);
        [$deck, $card] = $this->deckWith($user, 1, ['finish' => Finish::Foil]);
        $card->cardStacks()->attach($this->stackFor($user, $card, 1, Finish::Foil)->id);
        $card->cardStacks()->attach($this->stackFor($user, $card, 1, Finish::Nonfoil)->id);

        $this->assertSame(50.5, DeckWorthService::totalForDeck($user, $deck));
    }

    #[Test]
    public function a_finish_without_a_price_counts_nothing_and_leaves_the_rest(): void
    {
        $user = User::factory()->create(['currency' => 'eur']);
        $printing = $this->makeCard('Unpriced Foil', 5.00, null, 3);
        $deck = Deck::create(['user_id' => $user->id, 'name' => 'Test Deck', 'format' => CardFormat::Legacy->value]);
        $card = DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $printing->oracle_id,
            'default_card_id' => $printing->id,
            'zone' => DeckZone::Main->value,
            'quantity' => 2,
            'finish' => Finish::Foil,
        ]);
        $card->cardStacks()->attach($this->stackFor($user, $card, 1, Finish::Nonfoil)->id);

        $this->assertSame(5.0, DeckWorthService::totalForDeck($user, $deck));
    }

    /**
     * A deck holding one card priced 1 € nonfoil and 100 € foil.
     *
     * @param  array<string, mixed>  $plan
     * @return array{0: Deck, 1: DeckCard}
     */
    private function deckWith(User $user, int $quantity, array $plan = []): array
    {
        $deck = Deck::create(['user_id' => $user->id, 'name' => 'Test Deck', 'format' => CardFormat::Legacy->value]);
        $printing = $this->makeCard('Foilable', 1.00, 100.00, 3);
        $card = DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $printing->oracle_id,
            'default_card_id' => $printing->id,
            'zone' => DeckZone::Main->value,
            'quantity' => $quantity,
            ...$plan,
        ]);

        return [$deck, $card];
    }

    private function stackFor(User $user, DeckCard $card, int $amount, Finish $finish, bool $proxy = false): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $card->default_card_id,
            'amount' => $amount,
            'finish' => $finish,
            'language' => 'en',
            'proxy' => $proxy,
        ]);
    }
}
