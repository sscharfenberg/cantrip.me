<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\DeckCardSearchService;
use App\Services\DeckCollectionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which printing a quick-add reaches for.
 *
 * Preference: the printing this deck has most copies of *free* in the
 * collection, else the newest printing — the rule that governed every add
 * before this, and still the rule when the collection has nothing free or
 * the collection-integration master switch is off.
 *
 * Covered at its two seams rather than end to end:
 * {@see DeckCollectionStatusService::freeCopiesForOracles} decides what
 * counts as free, and {@see DeckCardSearchService::preferredPrintingId}
 * decides which free printing wins. The search path that joins them
 * (`searchOracleCardsForDeck`) orders by `CHAR_LENGTH` and so is
 * MariaDB-only, while its write-heavy fixtures rule out the Staging
 * suite — see the testsuite split in `CLAUDE.md`.
 */
class QuickAddPrintingPreferenceTest extends TestCase
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

    private function makeSet(string $releasedAt): Set
    {
        return Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => $releasedAt,
            'card_count' => 1,
            'set_type' => 'expansion',
            'scryfall_uri' => 'https://example.com/set',
            'path' => 'tst',
        ]);
    }

    private function makeOracle(string $name = 'Lightning Bolt'): OracleCard
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

    private function makePrinting(OracleCard $oracle, Set $set): DefaultCard
    {
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
            'name' => 'Quick Add Deck',
            'format' => CardFormat::Legacy->value,
        ]);
    }

    private function stack(User $user, DefaultCard $printing, int $amount, ?Container $container = null): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $printing->id,
            'container_id' => $container?->id,
            'amount' => $amount,
            'finish' => 1,
            'language' => 'en',
        ]);
    }

    private function container(User $user, string $name): Container
    {
        return Container::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
    }

    /** Free copies of `$oracle`'s printings from `$deck`'s point of view. */
    private function freeFor(Deck $deck, OracleCard $oracle): array
    {
        return DeckCollectionStatusService::freeCopiesForOracles($deck->fresh(), [$oracle->id])[$oracle->id] ?? [];
    }

    // ── what counts as free ───────────────────────────────────────────────

    #[Test]
    public function free_copies_are_summed_per_printing(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracle();
        $split = $this->makePrinting($oracle, $this->makeSet('2010-01-01'));
        $single = $this->makePrinting($oracle, $this->makeSet('2020-01-01'));
        $this->stack($user, $split, 1);
        $this->stack($user, $split, 1);
        $this->stack($user, $single, 1);

        $free = $this->freeFor($deck, $oracle);

        $this->assertSame(2, $free[$split->id]);
        $this->assertSame(1, $free[$single->id]);
    }

    #[Test]
    public function printings_come_back_newest_first(): void
    {
        // The order is load-bearing: `preferredPrintingId` breaks a tie by
        // keeping the first of several equal maxima, which is only the
        // newest printing if the map arrives in this order.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracle();
        $oldest = $this->makePrinting($oracle, $this->makeSet('1993-08-05'));
        $middle = $this->makePrinting($oracle, $this->makeSet('2010-01-01'));
        $newest = $this->makePrinting($oracle, $this->makeSet('2026-01-01'));
        // Inserted oldest-first so insertion order can't accidentally pass.
        $this->stack($user, $oldest, 1);
        $this->stack($user, $middle, 1);
        $this->stack($user, $newest, 1);

        $this->assertSame(
            [$newest->id, $middle->id, $oldest->id],
            array_keys($this->freeFor($deck, $oracle))
        );
    }

    #[Test]
    public function copies_claimed_by_another_deck_are_not_free(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $oracle = $this->makeOracle();
        $claimed = $this->makePrinting($oracle, $this->makeSet('2010-01-01'));
        $stack = $this->stack($user, $claimed, 4);
        $otherRow = DeckCard::create([
            'deck_id' => $otherDeck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $claimed->id,
            'zone' => 'main',
            'quantity' => 4,
        ]);
        $otherRow->cardStacks()->attach($stack->id);

        $this->assertSame([], $this->freeFor($deck, $oracle));
    }

    #[Test]
    public function copies_in_another_decks_deckbox_are_not_free(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $otherBox = $this->container($user, 'Other deckbox');
        $otherDeck->update(['container_id' => $otherBox->id]);
        $oracle = $this->makeOracle();
        $boxed = $this->makePrinting($oracle, $this->makeSet('2010-01-01'));
        $this->stack($user, $boxed, 4, $otherBox);

        $this->assertSame([], $this->freeFor($deck, $oracle));
    }

    #[Test]
    public function copies_in_this_decks_own_deckbox_stay_free(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $ownBox = $this->container($user, 'This deck');
        $deck->update(['container_id' => $ownBox->id]);
        $oracle = $this->makeOracle();
        $mine = $this->makePrinting($oracle, $this->makeSet('2010-01-01'));
        $this->stack($user, $mine, 2, $ownBox);

        $this->assertSame([$mine->id => 2], $this->freeFor($deck->fresh(), $oracle));
    }

    #[Test]
    public function nothing_is_free_while_the_master_switch_is_off(): void
    {
        $user = User::factory()->create(['collection_integration_enabled' => false]);
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracle();
        $printing = $this->makePrinting($oracle, $this->makeSet('2010-01-01'));
        $this->stack($user, $printing, 4);

        $this->assertSame([], DeckCollectionStatusService::freeCopiesForOracles($deck->fresh(), [$oracle->id]));
    }

    #[Test]
    public function it_asks_nothing_of_the_database_without_oracle_ids(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user)->fresh();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $free = DeckCollectionStatusService::freeCopiesForOracles($deck, []);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $free);
        $this->assertCount(0, $queries);
    }

    // ── which free printing wins ──────────────────────────────────────────

    #[Test]
    public function the_printing_with_the_most_free_copies_wins(): void
    {
        // First entry is the newest printing, so this also proves count
        // beats recency rather than the map order deciding it.
        $chosen = DeckCardSearchService::preferredPrintingId(
            ['newest' => 1, 'older' => 3],
            'fallback',
        );

        $this->assertSame('older', $chosen);
    }

    #[Test]
    public function a_tie_keeps_the_newest_of_the_tied_printings(): void
    {
        $chosen = DeckCardSearchService::preferredPrintingId(
            ['newest' => 2, 'older' => 2],
            'fallback',
        );

        $this->assertSame('newest', $chosen);
    }

    #[Test]
    public function a_single_free_printing_is_used_whatever_its_count(): void
    {
        $this->assertSame('only', DeckCardSearchService::preferredPrintingId(['only' => 1], 'fallback'));
    }

    #[Test]
    public function it_falls_back_to_the_newest_printing_when_nothing_is_free(): void
    {
        $this->assertSame('fallback', DeckCardSearchService::preferredPrintingId([], 'fallback'));
    }

    #[Test]
    public function it_stays_null_when_there_is_no_printing_at_all(): void
    {
        // A card with no printings at all — the quick-add row renders
        // disabled rather than POSTing a null id.
        $this->assertNull(DeckCardSearchService::preferredPrintingId([], null));
    }
}
