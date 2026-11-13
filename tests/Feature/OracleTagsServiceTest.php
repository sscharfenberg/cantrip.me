<?php

namespace Tests\Feature;

use App\Notifications\ScryfallUpdateFailedNotification;
use App\Services\CardNameNormalizer;
use App\Services\Scryfall\OracleTagsService;
use App\Services\Scryfall\ScryfallRunStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see OracleTagsService}'s rule that a tag sync with
 * nothing trustworthy to write keeps the previous values.
 *
 * The case that matters is the nightly shadow build: its freshly
 * imported `oracle_cards__shadow` starts with every tag column cleared,
 * so a failed search used to "leave the column untouched" — cleared —
 * and the swap put that live. These tests build that situation: the
 * live table holds last night's values, the shadow table holds the same
 * cards with the columns cleared, and Scryfall's search is faked.
 *
 * The Local PHPUnit suite uses SQLite. The defensive `mysql` skip
 * keeps the test out of a misconfigured `composer test:mysql`
 * invocation (which would wipe live data via `RefreshDatabase`).
 */
class OracleTagsServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ARMAGEDDON = '00000000-0000-0000-0000-00000000000a';

    private const RAVAGES = '00000000-0000-0000-0000-00000000000b';

    private const DELTA = '00000000-0000-0000-0000-00000000000c';

    private const CULTIVATE = '00000000-0000-0000-0000-00000000000d';

    private const DELTA_TEXT = '{T}, Pay 1 life, Sacrifice Polluted Delta: Search your library for an Island or Swamp card, put it onto the battlefield, then shuffle.';

    private const CULTIVATE_TEXT = 'Search your library for up to two basic land cards, reveal those cards, put one onto the battlefield tapped and the other into your hand, then shuffle.';

    /**
     * Hard-skip on real MariaDB connections. See
     * {@see DeckBulkClaimControllerTest::setUp} for
     * the rationale.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }

        Sleep::fake();
        Notification::fake();
        ScryfallRunStats::reset();
    }

    #[Test]
    public function a_successful_sync_writes_what_scryfall_found_and_ignores_last_nights_values(): void
    {
        $this->seedLastNight();
        $this->fakeSearch([
            'otag:mass-land-denial' => [$this->card(self::RAVAGES, 'Ravages of War')],
        ]);

        (new OracleTagsService)->syncOracleTags(shadow: true);

        $this->assertSame([self::RAVAGES], $this->shadowIdsWhere('mld', true));
        $this->assertSame([self::DELTA => 'typed:UB'], $this->shadowValues('fetch_pattern'));
        $this->assertSame([self::CULTIVATE => 'basic'], $this->shadowValues('land_search_pattern'));
        $this->assertSame([], ScryfallRunStats::$oracleTagColumnsCarriedOver);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_failed_search_carries_the_live_values_into_the_shadow_build(): void
    {
        $this->seedLastNight();
        $this->fakeSearch(['otag:mass-land-denial' => 500]);

        (new OracleTagsService)->syncOracleTags(shadow: true);

        $this->assertSame([self::ARMAGEDDON], $this->shadowIdsWhere('mld', true), 'last night\'s mld must survive the swap');
        $this->assertSame([self::DELTA => 'typed:UB'], $this->shadowValues('fetch_pattern'), 'other syncs run normally');
        $this->assertSame(['mld'], array_keys(ScryfallRunStats::$oracleTagColumnsCarriedOver));
        Notification::assertSentOnDemand(
            ScryfallUpdateFailedNotification::class,
            fn ($notification): bool => $notification->commandName === 'scryfall:oracle-tags'
                && str_contains($notification->exception->getMessage(), 'mld: the Scryfall search for otag:mass-land-denial failed'),
        );
    }

    #[Test]
    public function a_search_that_finds_nothing_carries_the_live_values_over(): void
    {
        $this->seedLastNight();
        $this->fakeSearch(['otag:fetchland' => []]);

        (new OracleTagsService)->syncOracleTags(shadow: true);

        $this->assertSame([self::DELTA => 'typed:UB'], $this->shadowValues('fetch_pattern'));
        $this->assertSame(['fetch_pattern'], array_keys(ScryfallRunStats::$oracleTagColumnsCarriedOver));
    }

    #[Test]
    public function cards_the_parser_cannot_classify_at_all_carry_the_live_values_over(): void
    {
        $this->seedLastNight();
        $this->fakeSearch([
            'otag:fetchland' => [$this->card(self::DELTA, 'Polluted Delta', 'Reworded beyond recognition.')],
        ]);

        (new OracleTagsService)->syncOracleTags(shadow: true);

        $this->assertSame([self::DELTA => 'typed:UB'], $this->shadowValues('fetch_pattern'));
        $this->assertSame(['fetch_pattern'], array_keys(ScryfallRunStats::$oracleTagColumnsCarriedOver));
    }

    #[Test]
    public function a_failed_search_leaves_the_live_column_untouched_when_run_standalone(): void
    {
        $this->seedLastNight();
        $this->fakeSearch(['otag:mass-land-denial' => 500]);

        (new OracleTagsService)->syncOracleTags();

        $this->assertSame(
            [self::ARMAGEDDON],
            DB::table('oracle_cards')->where('mld', true)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(['mld'], array_keys(ScryfallRunStats::$oracleTagColumnsCarriedOver));
        Notification::assertNothingSent();
    }

    /**
     * Last night's live values — Armageddon is mld, Polluted Delta a
     * typed fetch, Cultivate a basic land search — and tonight's shadow
     * import of the same four cards with every tag column cleared.
     */
    private function seedLastNight(): void
    {
        DB::statement('CREATE TABLE oracle_cards__shadow AS SELECT * FROM oracle_cards WHERE 0');

        $cards = [
            [self::ARMAGEDDON, 'Armageddon', true, null, null],
            [self::RAVAGES, 'Ravages of War', false, null, null],
            [self::DELTA, 'Polluted Delta', false, 'typed:UB', null],
            [self::CULTIVATE, 'Cultivate', false, null, 'basic'],
        ];
        foreach ($cards as [$id, $name, $mld, $fetch, $landSearch]) {
            $row = [
                'id' => $id,
                'name' => $name,
                'searchable_name' => CardNameNormalizer::normalize($name),
                'collector_number' => '1',
                'layout' => 'normal',
                'lang' => 'en',
                'cmc' => 0,
                'reserved' => false,
                'game_changer' => false,
                'scryfall_uri' => 'https://scryfall.test/'.$id,
            ];
            DB::table('oracle_cards')->insert([...$row, 'mld' => $mld, 'fetch_pattern' => $fetch, 'land_search_pattern' => $landSearch]);
            DB::table('oracle_cards__shadow')->insert([...$row, 'mld' => false, 'fetch_pattern' => null, 'land_search_pattern' => null]);
        }
    }

    /**
     * Fake Scryfall's card search. Each sync's query answers with the
     * given cards, or the given HTTP status; queries not listed answer
     * with tonight's normal result for that tag.
     *
     * @param  array<string, list<array<string, mixed>>|int>  $answers  Keyed by the start of the query.
     */
    private function fakeSearch(array $answers): void
    {
        $answers += [
            'otag:mass-land-denial' => [$this->card(self::ARMAGEDDON, 'Armageddon')],
            'otag:fetchland' => [$this->card(self::DELTA, 'Polluted Delta', self::DELTA_TEXT)],
            'otag:tutor-land' => [$this->card(self::CULTIVATE, 'Cultivate', self::CULTIVATE_TEXT)],
        ];

        Http::fake(function (Request $request) use ($answers) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
            foreach ($answers as $prefix => $answer) {
                if (str_starts_with((string) $params['q'], $prefix)) {
                    return is_int($answer)
                        ? Http::response(['object' => 'error'], $answer)
                        : Http::response(['object' => 'list', 'has_more' => false, 'data' => $answer]);
                }
            }

            return Http::response(['object' => 'error'], 404);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $oracleId, string $name, string $oracleText = ''): array
    {
        return [
            'oracle_id' => $oracleId,
            'name' => $name,
            'type_line' => 'Sorcery',
            'oracle_text' => $oracleText,
        ];
    }

    /**
     * @return list<string>
     */
    private function shadowIdsWhere(string $column, mixed $value): array
    {
        return DB::table('oracle_cards__shadow')->where($column, $value)->orderBy('id')->pluck('id')->all();
    }

    /**
     * @return array<string, string>
     */
    private function shadowValues(string $column): array
    {
        return DB::table('oracle_cards__shadow')->whereNotNull($column)->orderBy('id')->pluck($column, 'id')->all();
    }
}
