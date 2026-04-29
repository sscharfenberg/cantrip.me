<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckZone;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\DeckCollectionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see DeckCollectionStatusService}.
 *
 *  - effectiveMode returns A / B / C correctly given the data shape.
 *  - statusForDeck resolves each of the five status values.
 *  - statusForDeck issues a single batched join per call (perf smoke).
 */
class DeckCollectionStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOracleCard(string $name): OracleCard
    {
        return OracleCard::create([
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
    }

    private function makeSet(): Set
    {
        return Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'set_type' => 'expansion',
            'scryfall_uri' => 'https://example.com/set',
            'path' => 'tst',
        ]);
    }

    private function makeDefaultCard(OracleCard $oracle, ?Set $set = null): DefaultCard
    {
        $set ??= $this->makeSet();

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $oracle->name,
            'searchable_name' => $oracle->searchable_name,
            'collector_number' => (string) random_int(1, 999),
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    private function makeDeck(User $user): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'Test Deck',
            'format' => CardFormat::Legacy->value,
        ]);
    }

    private function makeDeckCard(Deck $deck, OracleCard $oracle, DefaultCard $default, int $quantity = 1): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $default->id,
            'zone' => DeckZone::Main->value,
            'quantity' => $quantity,
        ]);
    }

    private function makeCardStack(User $user, DefaultCard $default, ?Container $container = null, int $amount = 1): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $default->id,
            'container_id' => $container?->id,
            'amount' => $amount,
            'finish' => 1,
            'language' => 'en',
        ]);
    }

    // ── effectiveMode ──────────────────────────────────────────────────────

    #[Test]
    public function mode_a_when_user_has_no_card_stacks(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);

        $this->assertSame('A', DeckCollectionStatusService::effectiveMode($user, $deck));
    }

    #[Test]
    public function mode_a_when_user_opted_out_via_master_switch(): void
    {
        $user = User::factory()->create(['collection_integration_enabled' => false]);
        $oracle = $this->makeOracleCard('Sol Ring');
        $default = $this->makeDefaultCard($oracle);
        $this->makeCardStack($user, $default);
        $deck = $this->makeDeck($user);

        $this->assertSame('A', DeckCollectionStatusService::effectiveMode($user, $deck));
    }

    #[Test]
    public function mode_b_when_user_has_stacks_but_deck_has_no_pivot_rows(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard('Sol Ring');
        $default = $this->makeDefaultCard($oracle);
        $this->makeCardStack($user, $default);
        $deck = $this->makeDeck($user);

        $this->assertSame('B', DeckCollectionStatusService::effectiveMode($user, $deck));
    }

    #[Test]
    public function mode_c_when_deck_has_at_least_one_pivot_row(): void
    {
        $user = User::factory()->create();
        $oracle = $this->makeOracleCard('Sol Ring');
        $default = $this->makeDefaultCard($oracle);
        $stack = $this->makeCardStack($user, $default);
        $deck = $this->makeDeck($user);
        $deckCard = $this->makeDeckCard($deck, $oracle, $default);
        $deckCard->cardStacks()->attach($stack->id);

        $this->assertSame('C', DeckCollectionStatusService::effectiveMode($user, $deck));
    }

    // ── statusForDeck ──────────────────────────────────────────────────────

    #[Test]
    public function status_for_deck_returns_each_of_the_five_states(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $set = $this->makeSet();

        // 1) claimed_for_this_deck
        $oracleA = $this->makeOracleCard('Card A');
        $defaultA = $this->makeDefaultCard($oracleA, $set);
        $stackA = $this->makeCardStack($user, $defaultA);
        $deckCardA = $this->makeDeckCard($deck, $oracleA, $defaultA);
        $deckCardA->cardStacks()->attach($stackA->id);

        // 2) available — owned, no pivot row at all
        $oracleB = $this->makeOracleCard('Card B');
        $defaultB = $this->makeDefaultCard($oracleB, $set);
        $this->makeCardStack($user, $defaultB);
        $deckCardB = $this->makeDeckCard($deck, $oracleB, $defaultB);

        // 3) claimed_by_other_deck
        $oracleC = $this->makeOracleCard('Card C');
        $defaultC = $this->makeDefaultCard($oracleC, $set);
        $stackC = $this->makeCardStack($user, $defaultC);
        $otherDeckCardC = $this->makeDeckCard($otherDeck, $oracleC, $defaultC);
        $otherDeckCardC->cardStacks()->attach($stackC->id);
        $deckCardC = $this->makeDeckCard($deck, $oracleC, $defaultC);

        // 4) wrong_printing — own a different printing of the same oracle card
        $oracleD = $this->makeOracleCard('Card D');
        $defaultD1 = $this->makeDefaultCard($oracleD, $set);
        $defaultD2 = $this->makeDefaultCard($oracleD, $set);
        $this->makeCardStack($user, $defaultD2);
        $deckCardD = $this->makeDeckCard($deck, $oracleD, $defaultD1);

        // 5) not_owned — no stacks of this oracle card at all
        $oracleE = $this->makeOracleCard('Card E');
        $defaultE = $this->makeDefaultCard($oracleE, $set);
        $deckCardE = $this->makeDeckCard($deck, $oracleE, $defaultE);

        $statuses = DeckCollectionStatusService::statusForDeck($deck);

        $this->assertSame('claimed_for_this_deck', $statuses[$deckCardA->id]);
        $this->assertSame('available', $statuses[$deckCardB->id]);
        $this->assertSame('claimed_by_other_deck', $statuses[$deckCardC->id]);
        $this->assertSame('wrong_printing', $statuses[$deckCardD->id]);
        $this->assertSame('not_owned', $statuses[$deckCardE->id]);
    }

    #[Test]
    public function status_for_deck_uses_a_bounded_number_of_queries(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $set = $this->makeSet();

        // Seed 10 deck cards with mixed status to ensure scaling.
        for ($i = 0; $i < 10; $i++) {
            $oracle = $this->makeOracleCard("Card {$i}");
            $default = $this->makeDefaultCard($oracle, $set);
            $this->makeDeckCard($deck, $oracle, $default);
            if ($i % 2 === 0) {
                $this->makeCardStack($user, $default);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        DeckCollectionStatusService::statusForDeck($deck);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Two queries: deck_cards lookup + the joined stacks query.
        // Asserting <= 3 to allow connection-level chatter on some drivers.
        $this->assertLessThanOrEqual(3, count($queries));
    }
}
