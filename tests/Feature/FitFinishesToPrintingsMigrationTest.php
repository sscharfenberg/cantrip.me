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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `fit_finishes_to_printings`: stacks the old "add all to collection" minted
 * nonfoil on foil- or etched-only printings — and the deck cards that took
 * those finishes over — get a finish their printing exists in.
 *
 * `RefreshDatabase` has already run the migration on an empty database, so
 * each test writes its rows — the impossible finishes straight into the
 * table, past the model that would fit them — and runs it again.
 */
class FitFinishesToPrintingsMigrationTest extends TestCase
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

    /**
     * Printing finishes (bitmask), the finish written, the finish expected.
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function finishes(): array
    {
        return [
            'nonfoil on a foil-only printing' => [2, 1, 2],
            'nonfoil on an etched-only printing' => [4, 1, 4],
            'nonfoil on a foil-and-etched printing takes foil' => [6, 1, 2],
            'foil on a nonfoil-only printing' => [1, 2, 1],
            'etched on a foil-and-etched printing stays' => [6, 4, 4],
            'foil on a dual printing stays' => [3, 2, 2],
            'a printing listing no finish is left alone' => [0, 2, 2],
        ];
    }

    #[Test]
    #[DataProvider('finishes')]
    public function a_stack_gets_a_finish_its_printing_exists_in(int $printingFinishes, int $written, int $expected): void
    {
        $user = User::factory()->create();
        $stack = CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $this->printing($printingFinishes)->id,
            'amount' => 1,
            'finish' => $written,
            'language' => 'en',
            'proxy' => false,
        ]);

        $this->migrate();

        $this->assertSame($expected, (int) DB::table('card_stacks')->where('id', $stack->id)->value('finish'));
    }

    #[Test]
    #[DataProvider('finishes')]
    public function a_deck_card_gets_a_finish_its_printing_exists_in(int $printingFinishes, int $written, int $expected): void
    {
        $user = User::factory()->create();
        $deck = Deck::create(['user_id' => $user->id, 'name' => 'Old Deck', 'format' => CardFormat::Legacy->value]);
        $printing = $this->printing($printingFinishes);
        $card = DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $printing->oracle_id,
            'default_card_id' => $printing->id,
            'zone' => DeckZone::Main->value,
            'quantity' => 1,
        ]);
        DB::table('deck_cards')->where('id', $card->id)->update(['finish' => $written]);

        $this->migrate();

        $this->assertSame($expected, (int) DB::table('deck_cards')->where('id', $card->id)->value('finish'));
    }

    #[Test]
    public function only_the_rows_with_an_impossible_finish_change(): void
    {
        $user = User::factory()->create();
        $foilOnly = $this->printing(2);
        $wrong = CardStack::create(['user_id' => $user->id, 'default_card_id' => $foilOnly->id, 'amount' => 3, 'finish' => 1, 'language' => 'de', 'proxy' => true]);
        $right = CardStack::create(['user_id' => $user->id, 'default_card_id' => $foilOnly->id, 'amount' => 1, 'finish' => 2, 'language' => 'en', 'proxy' => false]);
        $updatedAt = DB::table('card_stacks')->where('id', $right->id)->value('updated_at');

        $this->migrate();

        $this->assertSame(
            [2, 3, 'de', 1],
            array_values((array) DB::table('card_stacks')->where('id', $wrong->id)->first(['finish', 'amount', 'language', 'proxy'])),
        );
        $this->assertSame($updatedAt, DB::table('card_stacks')->where('id', $right->id)->value('updated_at'));
    }

    private function migrate(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_29_192817_fit_finishes_to_printings.php');
        $migration->up();
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
}
