<?php

namespace Tests\Feature;

use App\Services\Scryfall\ScryfallRunStats;
use App\Services\Scryfall\SetsService;
use App\Services\Scryfall\SymbolsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Set icons and mana symbols: one SVG that cannot be downloaded must not
 * end the step — it used to throw out of `scryfall:sets` and with it the
 * whole nightly run — and a new set icon arrives next to the old one,
 * which goes only once the live sets point at the new one.
 *
 * Runs the shadow target because the live path truncates with MariaDB-only
 * `SET FOREIGN_KEY_CHECKS`. Skipped on the staging suite — uses
 * RefreshDatabase.
 */
class ScryfallSvgDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Uses RefreshDatabase; never run against MariaDB.');
        }
        Sleep::fake();
        ScryfallRunStats::reset();
        Storage::fake('set');
        Storage::fake('symbol');
    }

    #[Test]
    public function a_set_icon_that_cannot_be_downloaded_keeps_the_older_icon(): void
    {
        Storage::disk('set')->put('abc--111.svg', '<svg>old</svg>');
        $this->fakeSets(Http::failedConnection('timed out'));

        (new SetsService)->updateSets(shadow: true);

        $this->assertSame('/set/abc--111.svg', DB::table('sets__shadow')->where('code', 'abc')->value('path'));
        $this->assertSame(1, ScryfallRunStats::$svgDownloadsFailed);
    }

    #[Test]
    public function the_fallback_is_the_newest_older_icon_not_a_legacy_one(): void
    {
        Storage::disk('set')->put('abc.svg', '<svg>legacy</svg>');
        Storage::disk('set')->put('abc--99.svg', '<svg>older</svg>');
        Storage::disk('set')->put('abc--111.svg', '<svg>newest before tonight</svg>');
        $this->fakeSets(Http::failedConnection('timed out'));

        (new SetsService)->updateSets(shadow: true);

        $this->assertSame('/set/abc--111.svg', DB::table('sets__shadow')->where('code', 'abc')->value('path'));
    }

    #[Test]
    public function part_files_on_the_svg_disks_are_cleared(): void
    {
        Storage::disk('set')->put('.abc--222.svg.part', 'half');
        Storage::disk('set')->put('abc--111.svg', '<svg>kept</svg>');
        Storage::disk('symbol')->put('.W.svg.part', 'half');

        $sets = new SetsService;

        $this->assertSame(1, $sets->deletePartialDownloads('set'));
        $this->assertSame(1, $sets->deletePartialDownloads('symbol'));
        Storage::disk('set')->assertMissing('.abc--222.svg.part');
        Storage::disk('set')->assertExists('abc--111.svg');
        Storage::disk('symbol')->assertMissing('.W.svg.part');
    }

    #[Test]
    public function a_set_icon_answering_with_an_error_neither_aborts_nor_points_at_nothing_when_an_old_one_exists(): void
    {
        Storage::disk('set')->put('abc--111.svg', '<svg>old</svg>');
        $this->fakeSets(Http::response('gone', 500));

        (new SetsService)->updateSets(shadow: true);

        $this->assertSame('/set/abc--111.svg', DB::table('sets__shadow')->where('code', 'abc')->value('path'));
        Storage::disk('set')->assertMissing('abc--222.svg');
    }

    #[Test]
    public function a_new_set_icon_arrives_next_to_the_old_one_which_goes_after_the_swap(): void
    {
        Storage::disk('set')->put('abc--111.svg', '<svg>old</svg>');
        Storage::disk('set')->put('abcd--111.svg', '<svg>another set</svg>');
        $this->fakeSets(Http::response('<svg>new</svg>'));
        $sets = new SetsService;

        $sets->updateSets(shadow: true);

        $this->assertSame('/set/abc--222.svg', DB::table('sets__shadow')->where('code', 'abc')->value('path'));
        Storage::disk('set')->assertExists('abc--111.svg');

        // The swap: live now points at the new icon.
        DB::table('sets')->insert(['id' => (string) Str::uuid(), 'code' => 'abc', 'name' => 'ABC', 'scryfall_uri' => 'x', 'set_type' => 'core', 'path' => '/set/abc--222.svg', 'card_count' => 1, 'digital' => false]);
        DB::table('sets')->insert(['id' => (string) Str::uuid(), 'code' => 'abcd', 'name' => 'ABCD', 'scryfall_uri' => 'x', 'set_type' => 'core', 'path' => '/set/abcd--111.svg', 'card_count' => 1, 'digital' => false]);

        $this->assertSame(1, $sets->deleteSupersededIcons());
        Storage::disk('set')->assertMissing('abc--111.svg');
        Storage::disk('set')->assertExists('abc--222.svg');
        Storage::disk('set')->assertExists('abcd--111.svg');
    }

    #[Test]
    public function a_symbol_that_cannot_be_downloaded_does_not_abort_the_step(): void
    {
        DB::statement('CREATE TABLE symbols__shadow AS SELECT * FROM symbols WHERE 0');
        Http::fake([
            'api.scryfall.com/symbology' => Http::response(['data' => [$this->symbol('{W}', 'W'), $this->symbol('{U}', 'U')]]),
            'svgs.test/card-symbols/W.svg' => Http::failedConnection('timed out'),
            'svgs.test/*' => Http::response('<svg>U</svg>'),
        ]);

        (new SymbolsService)->updateSymbols(shadow: true);

        $this->assertSame(2, DB::table('symbols__shadow')->count());
        $this->assertSame(1, ScryfallRunStats::$svgDownloadsFailed);
        Storage::disk('symbol')->assertMissing('W.svg');
        Storage::disk('symbol')->assertExists('U.svg');
    }

    #[Test]
    public function a_failed_catalog_request_leaves_the_live_sets_and_symbols_as_they_were(): void
    {
        DB::table('sets')->insert(['id' => (string) Str::uuid(), 'code' => 'abc', 'name' => 'ABC', 'scryfall_uri' => 'x', 'set_type' => 'core', 'path' => '/set/abc--111.svg', 'card_count' => 1, 'digital' => false]);
        DB::table('symbols')->insert(array_merge(
            array_diff_key($this->symbol('{W}', 'W'), ['colors' => true]),
            ['id' => (string) Str::uuid(), 'colors' => 'W', 'path' => '/symbol/W.svg'],
        ));
        Http::fake(['api.scryfall.com/*' => Http::response(['object' => 'error'], 503)]);

        foreach (['/sets request failed' => fn () => (new SetsService)->updateSets(), '/symbology request failed' => fn () => (new SymbolsService)->updateSymbols()] as $message => $run) {
            try {
                $run();
                $this->fail('expected the run to fail');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertSame(1, DB::table('sets')->count());
        $this->assertSame(1, DB::table('symbols')->count());
    }

    /**
     * Fake Scryfall's /sets catalog with one set whose icon carries
     * timestamp 222, and answer the icon download with $icon.
     */
    private function fakeSets(mixed $icon): void
    {
        DB::statement('CREATE TABLE sets__shadow AS SELECT * FROM sets WHERE 0');
        Http::fake([
            'api.scryfall.com/sets' => Http::response(['data' => [[
                'id' => (string) Str::uuid(),
                'code' => 'abc',
                'name' => 'ABC',
                'scryfall_uri' => 'https://scryfall.test/sets/abc',
                'set_type' => 'core',
                'icon_svg_uri' => 'https://svgs.test/sets/abc.svg?222',
                'card_count' => 1,
                'digital' => false,
            ]]]),
            'svgs.test/*' => $icon,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function symbol(string $symbol, string $looseVariant): array
    {
        return [
            'symbol' => $symbol,
            'svg_uri' => "https://svgs.test/card-symbols/$looseVariant.svg",
            'loose_variant' => $looseVariant,
            'english' => $looseVariant,
            'represents_mana' => true,
            'appears_in_mana_costs' => true,
            'transposable' => false,
            'hybrid' => false,
            'phyrexian' => false,
            'funny' => false,
            'cmc' => 1,
            'colors' => [$looseVariant],
        ];
    }
}
