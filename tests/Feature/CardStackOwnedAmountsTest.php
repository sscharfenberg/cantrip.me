<?php

namespace Tests\Feature;

use App\Models\CardStack;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use App\Services\CardStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see CardStackService::ownedAmountsFor}, which feeds the
 * ownership badge on `CardFaceImage`'s panel.
 *
 * The badge is a positive signal only, and the single gate for the whole
 * feature lives in this one method: a guest, a user with the
 * collection-integration master switch off, and a printing nobody owns all
 * have to come back as "nothing to show".
 */
class CardStackOwnedAmountsTest extends TestCase
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

    private function makePrinting(string $name = 'Sol Ring'): DefaultCard
    {
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => '',
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);

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

    private function stack(User $user, DefaultCard $printing, int $amount, bool $proxy = false): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $printing->id,
            'amount' => $amount,
            'finish' => 1,
            'language' => 'en',
            'proxy' => $proxy,
        ]);
    }

    #[Test]
    public function it_sums_every_stack_of_the_same_printing(): void
    {
        // Distinct finishes / conditions / containers of one printing live
        // in separate stacks; the badge shows the shelf total.
        $user = User::factory()->create();
        $printing = $this->makePrinting();
        $this->stack($user, $printing, 2);
        $this->stack($user, $printing, 3);

        $amounts = CardStackService::ownedAmountsFor($user, [$printing->id]);

        $this->assertSame([$printing->id => 5], $amounts);
    }

    #[Test]
    public function it_keys_each_printing_separately(): void
    {
        $user = User::factory()->create();
        $one = $this->makePrinting('Sol Ring');
        $two = $this->makePrinting('Mana Crypt');
        $this->stack($user, $one, 1);
        $this->stack($user, $two, 4);

        $amounts = CardStackService::ownedAmountsFor($user, [$one->id, $two->id]);

        $this->assertSame(1, $amounts[$one->id]);
        $this->assertSame(4, $amounts[$two->id]);
    }

    #[Test]
    public function it_omits_a_printing_the_user_owns_none_of(): void
    {
        // Absent rather than 0 — the caller's `?? null` then collapses
        // "owns none" into the same nullish value as "nothing to show",
        // and the badge stays off.
        $user = User::factory()->create();
        $owned = $this->makePrinting('Sol Ring');
        $unowned = $this->makePrinting('Black Lotus');
        $this->stack($user, $owned, 1);

        $amounts = CardStackService::ownedAmountsFor($user, [$owned->id, $unowned->id]);

        $this->assertArrayNotHasKey($unowned->id, $amounts);
        $this->assertNull($amounts[$unowned->id] ?? null);
    }

    #[Test]
    public function it_returns_nothing_while_the_master_switch_is_off(): void
    {
        $user = User::factory()->create(['collection_integration_enabled' => false]);
        $printing = $this->makePrinting();
        $this->stack($user, $printing, 4);

        $this->assertSame([], CardStackService::ownedAmountsFor($user, [$printing->id]));
    }

    #[Test]
    public function it_returns_nothing_for_a_guest(): void
    {
        $printing = $this->makePrinting();

        $this->assertSame([], CardStackService::ownedAmountsFor(null, [$printing->id]));
    }

    #[Test]
    public function it_ignores_stacks_owned_by_somebody_else(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $printing = $this->makePrinting();
        $this->stack($stranger, $printing, 4);

        $this->assertSame([], CardStackService::ownedAmountsFor($user, [$printing->id]));
    }

    #[Test]
    public function it_counts_proxies_like_any_other_copy(): void
    {
        // Deliberate: a proxy is a card in a sleeve as far as "do I have
        // this to hand" goes, and every other collection count agrees.
        $user = User::factory()->create();
        $printing = $this->makePrinting();
        $this->stack($user, $printing, 1);
        $this->stack($user, $printing, 2, proxy: true);

        $this->assertSame([$printing->id => 3], CardStackService::ownedAmountsFor($user, [$printing->id]));
    }

    #[Test]
    public function it_asks_nothing_of_the_database_without_ids(): void
    {
        $user = User::factory()->create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $amounts = CardStackService::ownedAmountsFor($user, []);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $amounts);
        $this->assertCount(0, $queries);
    }

    #[Test]
    public function it_resolves_many_printings_in_one_query(): void
    {
        $user = User::factory()->create();
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $printing = $this->makePrinting("Card {$i}");
            $ids[] = $printing->id;
            $this->stack($user, $printing, $i + 1);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        CardStackService::ownedAmountsFor($user, $ids);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }
}
