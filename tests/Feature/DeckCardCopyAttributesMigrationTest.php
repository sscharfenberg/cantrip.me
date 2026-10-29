<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckZone;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The backfill of `add_copy_attributes_to_deck_cards_table`: what existing
 * decks look like the moment the columns arrive on prod.
 *
 * `RefreshDatabase` has already run the migration on an empty database, so
 * each test writes its rows, resets them to the bare column defaults the
 * ALTER would have given them, and runs the two backfill steps itself.
 */
class DeckCardCopyAttributesMigrationTest extends TestCase
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
    public function a_card_on_a_printing_without_nonfoil_gets_its_first_finish(): void
    {
        [$user, $deck] = $this->deck();
        $foilOnly = $this->card($deck, $this->printing(2), 1);
        $etchedOnly = $this->card($deck, $this->printing(4), 1);
        $etchedAndFoil = $this->card($deck, $this->printing(6), 1);
        $plain = $this->card($deck, $this->printing(3), 1);

        $this->backfill();

        $this->assertSame(
            [2, 4, 2, 1],
            array_map(fn (DeckCard $card): int => $this->raw($card)->finish, [$foilOnly, $etchedOnly, $etchedAndFoil, $plain]),
        );
    }

    #[Test]
    public function a_card_its_agreeing_stacks_cover_takes_their_values(): void
    {
        [$user, $deck] = $this->deck();
        $printing = $this->printing(3);
        $card = $this->card($deck, $printing, 3);
        $this->claim($card, $this->stack($user, $printing, 2, 2, 'de', true));
        $this->claim($card, $this->stack($user, $printing, 1, 2, 'de', true));

        $this->backfill();

        $row = $this->raw($card);
        $this->assertSame([2, 'de', 1], [(int) $row->finish, $row->language, (int) $row->proxy]);
    }

    #[Test]
    public function a_partly_covered_card_keeps_the_defaults(): void
    {
        [$user, $deck] = $this->deck();
        $printing = $this->printing(3);
        $card = $this->card($deck, $printing, 3);
        $this->claim($card, $this->stack($user, $printing, 2, 2, 'de', true));

        $this->backfill();

        $row = $this->raw($card);
        $this->assertSame([1, 'en', 0], [(int) $row->finish, $row->language, (int) $row->proxy]);
    }

    #[Test]
    public function stacks_that_disagree_keep_the_defaults(): void
    {
        [$user, $deck] = $this->deck();
        $printing = $this->printing(3);
        $card = $this->card($deck, $printing, 2);
        $this->claim($card, $this->stack($user, $printing, 1, 2, 'de', false));
        $this->claim($card, $this->stack($user, $printing, 1, 2, 'fr', false));

        $this->backfill();

        $this->assertSame('en', $this->raw($card)->language);
    }

    #[Test]
    public function stacks_that_disagree_on_finish_keep_the_defaults(): void
    {
        [$user, $deck] = $this->deck();
        $printing = $this->printing(3);
        $card = $this->card($deck, $printing, 2);
        $this->claim($card, $this->stack($user, $printing, 1, 2, 'de', false));
        $this->claim($card, $this->stack($user, $printing, 1, 1, 'de', false));

        $this->backfill();

        $this->assertSame([1, 'en'], [(int) $this->raw($card)->finish, $this->raw($card)->language]);
    }

    #[Test]
    public function stacks_that_disagree_on_proxy_keep_the_defaults(): void
    {
        [$user, $deck] = $this->deck();
        $printing = $this->printing(3);
        $card = $this->card($deck, $printing, 2);
        $this->claim($card, $this->stack($user, $printing, 1, 2, 'de', true));
        $this->claim($card, $this->stack($user, $printing, 1, 2, 'de', false));

        $this->backfill();

        $this->assertSame([1, 'en', 0], [(int) $this->raw($card)->finish, $this->raw($card)->language, (int) $this->raw($card)->proxy]);
    }

    #[Test]
    public function stacks_holding_more_copies_than_the_card_still_hand_it_their_values(): void
    {
        [$user, $deck] = $this->deck();
        $printing = $this->printing(3);
        $card = $this->card($deck, $printing, 1);
        $this->claim($card, $this->stack($user, $printing, 3, 2, 'ja', false));

        $this->backfill();

        $this->assertSame([2, 'ja'], [(int) $this->raw($card)->finish, $this->raw($card)->language]);
    }

    #[Test]
    public function a_stack_of_another_printing_keeps_the_defaults(): void
    {
        [$user, $deck] = $this->deck();
        $printing = $this->printing(3);
        $other = $this->printing(3);
        $card = $this->card($deck, $printing, 1);
        $this->claim($card, $this->stack($user, $other, 1, 2, 'de', false));

        $this->backfill();

        $this->assertSame([1, 'en'], [(int) $this->raw($card)->finish, $this->raw($card)->language]);
    }

    /**
     * Put every row back to the bare column defaults, then run both steps.
     */
    private function backfill(): void
    {
        DB::table('deck_cards')->update(['finish' => 1, 'language' => 'en', 'proxy' => false]);

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_29_174931_add_copy_attributes_to_deck_cards_table.php');
        foreach (['fitFinishToPrinting', 'adoptClaimedValues'] as $step) {
            (new ReflectionMethod($migration, $step))->invoke($migration);
        }
    }

    private function raw(DeckCard $card): object
    {
        return DB::table('deck_cards')->where('id', $card->id)->first();
    }

    /**
     * @return array{0: User, 1: Deck}
     */
    private function deck(): array
    {
        $user = User::factory()->create();

        return [$user, Deck::create(['user_id' => $user->id, 'name' => 'Old Deck', 'format' => CardFormat::Legacy->value])];
    }

    private function printing(int $finishes): DefaultCard
    {
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => 'Card '.Str::random(4),
            'searchable_name' => 'card',
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => 'R',
            'scryfall_uri' => 'https://example.com/card',
        ]);
        $set = Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2020-01-01',
            'card_count' => 1,
            'set_type' => 'expansion',
            'scryfall_uri' => 'https://example.com/set',
            'path' => 'tst',
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
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    private function card(Deck $deck, DefaultCard $printing, int $quantity): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $printing->oracle_id,
            'default_card_id' => $printing->id,
            'zone' => DeckZone::Main->value,
            'quantity' => $quantity,
        ]);
    }

    private function stack(User $user, DefaultCard $printing, int $amount, int $finish, string $language, bool $proxy): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $printing->id,
            'amount' => $amount,
            'finish' => $finish,
            'language' => $language,
            'proxy' => $proxy,
        ]);
    }

    private function claim(DeckCard $card, CardStack $stack): void
    {
        $card->cardStacks()->attach($stack->id);
    }
}
