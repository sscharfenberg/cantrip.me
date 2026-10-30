<?php

namespace Tests\Feature;

use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The card preview (`GET /cards/{defaultCard}/preview`, the deck page's card
 * modal) prices every finish of the printing in euro and US dollar, and lists the viewer's copies
 * one row per container and kind of copy — finish, language, proxy — so the
 * modal can mark foils, proxies and foreign-language copies.
 */
class CardPreviewCopiesTest extends TestCase
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
    public function it_prices_each_finish_in_both_currencies_whatever_the_viewers_own(): void
    {
        $user = User::factory()->create(['currency' => 'usd']);
        $printing = $this->printing($this->oracle(), [
            'price_eur' => 15.42,
            'price_eur_foil' => 31.12,
            'price_usd' => 17.5,
            'price_usd_etched' => 40.0,
        ]);

        $this->actingAs($user)
            ->getJson(route('card.preview', $printing))
            ->assertOk()
            ->assertJsonPath('prices', [
                'eur' => ['nonfoil' => 15.42, 'foil' => 31.12, 'etched' => null],
                'usd' => ['nonfoil' => 17.5, 'foil' => null, 'etched' => 40],
            ]);
    }

    #[Test]
    public function it_lists_the_copies_one_row_per_container_finish_language_and_proxy_flag(): void
    {
        $user = User::factory()->create();
        $oracle = $this->oracle();
        $printing = $this->printing($oracle);
        $other = $this->printing($oracle);
        $third = $this->printing($oracle);
        $binder = Container::create(['user_id' => $user->id, 'name' => 'Binder', 'type' => 'binder', 'sort_order' => 1]);
        $this->stack($user, $printing, $binder, 2);
        $this->stack($user, $printing, $binder, 1, ['condition' => 'played']);
        $this->stack($user, $printing, $binder, 1, ['finish' => Finish::Foil->value]);
        $this->stack($user, $printing, $binder, 1, ['language' => 'de']);
        $this->stack($user, $printing, $binder, 1, ['proxy' => true]);
        $this->stack($user, $printing, null, 1);
        $this->stack($user, $other, $binder, 2, ['finish' => Finish::Foil->value]);
        $this->stack($user, $other, $binder, 1);
        $this->stack($user, $third, $binder, 1);

        $collection = $this->actingAs($user)
            ->getJson(route('card.preview', $printing))
            ->assertOk()
            ->json('collection');

        $row = fn (?string $container, int $amount, string $finish = 'nonfoil', string $language = 'en', bool $proxy = false): array => [
            'container_name' => $container,
            'finish' => $finish,
            'language' => $language,
            'proxy' => $proxy,
            'amount' => $amount,
        ];
        $this->assertEqualsCanonicalizing([
            $row('Binder', 3),
            $row('Binder', 1, 'foil'),
            $row('Binder', 1, language: 'de'),
            $row('Binder', 1, proxy: true),
            $row(null, 1),
        ], $collection['same_printing']);
        $this->assertEqualsCanonicalizing(
            [[$other->id, 2, 'foil'], [$other->id, 1, 'nonfoil'], [$third->id, 1, 'nonfoil']],
            array_map(fn (array $entry): array => [$entry['default_card_id'], $entry['amount'], $entry['finish']], $collection['other_printings']),
        );
    }

    private function oracle(): OracleCard
    {
        return OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => 'Sol Ring',
            'searchable_name' => 'sol ring',
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => '',
            'scryfall_uri' => 'https://example.com/sol-ring',
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function printing(OracleCard $oracle, array $attributes = []): DefaultCard
    {
        $code = Str::lower(Str::random(4));
        $set = Set::create([
            'id' => (string) Str::uuid(),
            'name' => "Set {$code}",
            'code' => $code,
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
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 3,
            'games' => 1,
            'rarity' => 'uncommon',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function stack(User $user, DefaultCard $printing, ?Container $container, int $amount, array $attributes = []): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $printing->id,
            'container_id' => $container?->id,
            'amount' => $amount,
            'finish' => Finish::Nonfoil->value,
            'language' => 'en',
            ...$attributes,
        ]);
    }
}
