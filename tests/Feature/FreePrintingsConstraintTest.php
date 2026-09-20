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
use App\Services\DeckCollectionStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see DeckCollectionStatusService::constrainToFreePrintings},
 * the SQL form of the "free copy" rule that powers the Add Cards modal's
 * "only show printings available in your collection" switch.
 *
 * The rule exists twice — once as SQL here, once as PHP folding in
 * `partitionCopies` — because one has to narrow a query before its LIMIT
 * and the other has to count copies in rows already fetched. The last test
 * in this file is the guard against the two drifting apart.
 */
class FreePrintingsConstraintTest extends TestCase
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

    private function makeSet(string $releasedAt = '2020-01-01'): Set
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

    private function makePrinting(OracleCard $oracle, ?Set $set = null): DefaultCard
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
            'set_id' => ($set ?? $this->makeSet())->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    private function makeDeck(User $user): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'Deck '.Str::random(4),
            'format' => CardFormat::Legacy->value,
        ]);
    }

    private function stack(User $user, DefaultCard $printing, int $amount = 1, ?Container $container = null): CardStack
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

    /** Claim a stack for a deck, the way mode C does. */
    private function claim(Deck $deck, DefaultCard $printing, CardStack $stack): void
    {
        $row = DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $printing->oracle_id,
            'default_card_id' => $printing->id,
            'zone' => 'main',
            'quantity' => 1,
        ]);
        $row->cardStacks()->attach($stack->id);
    }

    /** Printing ids surviving the constraint, for the whole table. */
    private function survivors(Deck $deck): array
    {
        $query = DB::table('default_cards');
        DeckCollectionStatusService::constrainToFreePrintings($query, $deck->fresh());

        return $query->pluck('default_cards.id')->sort()->values()->all();
    }

    #[Test]
    public function it_keeps_a_printing_with_an_unclaimed_stack(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $printing = $this->makePrinting($this->makeOracle());
        $this->stack($user, $printing);

        $this->assertSame([$printing->id], $this->survivors($deck));
    }

    #[Test]
    public function it_drops_a_printing_nobody_owns(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $this->makePrinting($this->makeOracle());

        $this->assertSame([], $this->survivors($deck));
    }

    #[Test]
    public function it_drops_a_printing_claimed_by_another_deck(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $printing = $this->makePrinting($this->makeOracle());
        $this->claim($otherDeck, $printing, $this->stack($user, $printing));

        $this->assertSame([], $this->survivors($deck));
    }

    #[Test]
    public function it_keeps_a_printing_claimed_for_this_very_deck(): void
    {
        // This deck's own claim is not somebody else's hold — the copy is
        // already earmarked here, so it is still this deck's to use.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $printing = $this->makePrinting($this->makeOracle());
        $this->claim($deck, $printing, $this->stack($user, $printing));

        $this->assertSame([$printing->id], $this->survivors($deck));
    }

    #[Test]
    public function it_drops_a_printing_sitting_in_another_decks_deckbox(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $otherBox = $this->container($user, 'Other deckbox');
        $otherDeck->update(['container_id' => $otherBox->id]);
        $printing = $this->makePrinting($this->makeOracle());
        $this->stack($user, $printing, 1, $otherBox);

        $this->assertSame([], $this->survivors($deck));
    }

    #[Test]
    public function it_keeps_a_printing_in_this_decks_own_deckbox(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $ownBox = $this->container($user, 'This deck');
        $deck->update(['container_id' => $ownBox->id]);
        $printing = $this->makePrinting($this->makeOracle());
        $this->stack($user, $printing, 1, $ownBox);

        $this->assertSame([$printing->id], $this->survivors($deck->fresh()));
    }

    #[Test]
    public function one_free_stack_is_enough_even_when_a_sibling_is_held(): void
    {
        // Four copies in another deck, one loose. The printing is available.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $printing = $this->makePrinting($this->makeOracle());
        $this->claim($otherDeck, $printing, $this->stack($user, $printing, 4));
        $this->stack($user, $printing, 1);

        $this->assertSame([$printing->id], $this->survivors($deck));
    }

    #[Test]
    public function it_ignores_stacks_owned_by_somebody_else(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $deck = $this->makeDeck($user);
        $printing = $this->makePrinting($this->makeOracle());
        $this->stack($stranger, $printing, 4);

        $this->assertSame([], $this->survivors($deck));
    }

    #[Test]
    public function it_ignores_a_container_belonging_to_another_users_deck(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $box = $this->container($user, 'My binder');
        $strangerDeck = $this->makeDeck($stranger);
        $strangerDeck->update(['container_id' => $box->id]);
        $deck = $this->makeDeck($user);
        $printing = $this->makePrinting($this->makeOracle());
        $this->stack($user, $printing, 1, $box);

        $this->assertSame([$printing->id], $this->survivors($deck));
    }

    #[Test]
    public function the_sql_filter_and_the_php_rule_agree(): void
    {
        // The drift guard. One fixture exercising every branch at once,
        // then both expressions of the rule are asked the same question:
        // `constrainToFreePrintings` (SQL, pre-LIMIT) must keep exactly the
        // printings `freeCopiesForOracles` (PHP fold) reports as free.
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($user);
        $stranger = User::factory()->create();
        $otherBox = $this->container($user, 'Other deckbox');
        $ownBox = $this->container($user, 'This deck');
        $otherDeck->update(['container_id' => $otherBox->id]);
        $deck->update(['container_id' => $ownBox->id]);
        $oracle = $this->makeOracle();

        $loose = $this->makePrinting($oracle);
        $this->stack($user, $loose, 2);

        $claimedElsewhere = $this->makePrinting($oracle);
        $this->claim($otherDeck, $claimedElsewhere, $this->stack($user, $claimedElsewhere, 3));

        $claimedHere = $this->makePrinting($oracle);
        $this->claim($deck, $claimedHere, $this->stack($user, $claimedHere, 1));

        $inOtherBox = $this->makePrinting($oracle);
        $this->stack($user, $inOtherBox, 4, $otherBox);

        $inOwnBox = $this->makePrinting($oracle);
        $this->stack($user, $inOwnBox, 1, $ownBox);

        $partlyHeld = $this->makePrinting($oracle);
        $this->claim($otherDeck, $partlyHeld, $this->stack($user, $partlyHeld, 4));
        $this->stack($user, $partlyHeld, 1);

        $unowned = $this->makePrinting($oracle);

        $strangersOnly = $this->makePrinting($oracle);
        $this->stack($stranger, $strangersOnly, 9);

        // Both sides are pinned to a stated answer, not merely to each
        // other — four of the nine printings are free, and which four is
        // spelled out here so a change of rule cannot pass by agreeing
        // with itself.
        $expected = collect([$loose->id, $claimedHere->id, $inOwnBox->id, $partlyHeld->id])
            ->sort()
            ->values()
            ->all();

        $fromPhp = array_keys(
            DeckCollectionStatusService::freeCopiesForOracles($deck->fresh(), [$oracle->id])[$oracle->id] ?? []
        );
        sort($fromPhp);

        $this->assertSame($expected, $fromPhp, 'the PHP fold disagrees with the stated answer');
        $this->assertSame($expected, $this->survivors($deck), 'the SQL filter disagrees with the stated answer');
        $this->assertNotContains($unowned->id, $fromPhp);
        $this->assertNotContains($strangersOnly->id, $fromPhp);
        $this->assertNotContains($claimedElsewhere->id, $fromPhp);
        $this->assertNotContains($inOtherBox->id, $fromPhp);
    }
}
