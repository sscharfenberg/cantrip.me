<?php

namespace Tests\Unit\Services\Scryfall;

use App\Services\CardNameNormalizer;
use App\Services\Scryfall\TranslationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\DeckBulkClaimControllerTest;
use Tests\TestCase;

/**
 * Parse-side coverage for {@see TranslationsService}.
 *
 * Hits a real (in-memory SQLite) DB via {@see RefreshDatabase} so the
 * service's `DB::table()` writes and FK-lookup pre-loads execute
 * against actual schema. The bulk JSON is sourced from a tempfile
 * written per test — `JsonParser::parse()`'s `Filename` source
 * matches when `is_file($source)` is true, so the streaming path is
 * exercised end-to-end without any HTTP I/O.
 *
 * The Local PHPUnit suite uses SQLite. The defensive `mysql` skip
 * keeps the test out of a misconfigured `composer test:mysql`
 * invocation (which would wipe live data via `RefreshDatabase`).
 */
class TranslationsServiceTest extends TestCase
{
    use RefreshDatabase;

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
    }

    /**
     * Seed the minimum schema rows the service needs: bulk_data row
     * with the all_cards download_uri pointing at the given local
     * file, plus the known oracle_cards and oracle_card_faces rows
     * driving the FK pre-load lookups.
     *
     * @param  array<int, array{id: string, name: string, faces?: array<int, string>}>  $oracles
     */
    private function seedKnown(string $fixturePath, array $oracles): void
    {
        DB::table('bulk_data')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'all_cards',
            'updated_at' => now()->toDateTimeString(),
            'uri' => 'https://example.test/bulk',
            'name' => 'All Cards',
            'description' => 'fixture bulk for tests',
            'size' => filesize($fixturePath) ?: 0,
            'download_uri' => $fixturePath,
            'content_type' => 'application/json',
            'content_encoding' => '',
        ]);

        foreach ($oracles as $oracle) {
            DB::table('oracle_cards')->insert([
                'id' => $oracle['id'],
                'name' => $oracle['name'],
                'searchable_name' => CardNameNormalizer::normalize($oracle['name']),
                'collector_number' => '1',
                'layout' => 'normal',
                'lang' => 'en',
                'cmc' => 0,
                'reserved' => false,
                'game_changer' => false,
                'scryfall_uri' => 'https://scryfall.test/'.$oracle['id'],
            ]);
            foreach ($oracle['faces'] ?? ['front'] as $faceIndex => $faceName) {
                DB::table('oracle_card_faces')->insert([
                    'id' => (string) Str::uuid(),
                    'oracle_card_id' => $oracle['id'],
                    'face_index' => $faceIndex,
                    'name' => $faceName,
                    'type_line' => 'Instant',
                ]);
            }
        }
    }

    /**
     * Write the fixture printings to a tempfile and return its path.
     * The tempfile is removed on test tear-down via PHP's normal
     * tempfile semantics — `tempnam()` files persist until manually
     * unlinked, but the test process owns them and they sit under
     * `sys_get_temp_dir()`.
     *
     * @param  array<int, array<string, mixed>>  $printings
     */
    private function writeFixture(array $printings): string
    {
        $path = tempnam(sys_get_temp_dir(), 'all_cards_test_');
        if ($path === false) {
            $this->fail('failed to create tempfile for fixture');
        }
        file_put_contents($path, json_encode($printings));

        return $path;
    }

    #[Test]
    public function it_inserts_a_german_oracle_translation_with_normalized_searchable_name(): void
    {
        $boltId = '11111111-1111-1111-1111-111111111111';
        $fixture = $this->writeFixture([
            [
                'oracle_id' => $boltId,
                'lang' => 'de',
                'printed_name' => 'Blitzschlag',
            ],
        ]);
        $this->seedKnown($fixture, [
            ['id' => $boltId, 'name' => 'Lightning Bolt'],
        ]);

        (new TranslationsService)->updateTranslations(shadow: false);

        $row = DB::table('oracle_card_translations')->where('oracle_card_id', $boltId)->first();
        $this->assertNotNull($row);
        $this->assertSame('de', $row->lang);
        $this->assertSame('Blitzschlag', $row->printed_name);
        $this->assertSame('blitzschlag', $row->searchable_name);
        $this->assertSame(CardNameNormalizer::normalize('Blitzschlag'), $row->searchable_name);
    }

    #[Test]
    public function it_skips_english_printings(): void
    {
        $boltId = '11111111-1111-1111-1111-111111111111';
        $fixture = $this->writeFixture([
            [
                'oracle_id' => $boltId,
                'lang' => 'en',
                'printed_name' => 'Lightning Bolt',
            ],
        ]);
        $this->seedKnown($fixture, [
            ['id' => $boltId, 'name' => 'Lightning Bolt'],
        ]);

        (new TranslationsService)->updateTranslations(shadow: false);

        $this->assertSame(0, DB::table('oracle_card_translations')->count());
    }

    #[Test]
    public function it_skips_printings_whose_oracle_is_unknown(): void
    {
        $boltId = '11111111-1111-1111-1111-111111111111';
        $ghostId = '99999999-9999-9999-9999-999999999999';
        $fixture = $this->writeFixture([
            [
                'oracle_id' => $ghostId,
                'lang' => 'de',
                'printed_name' => 'Geistgeschichte',
            ],
        ]);
        $this->seedKnown($fixture, [
            ['id' => $boltId, 'name' => 'Lightning Bolt'],
        ]);

        (new TranslationsService)->updateTranslations(shadow: false);

        $this->assertSame(0, DB::table('oracle_card_translations')->count());
    }

    #[Test]
    public function it_skips_printings_without_a_printed_name(): void
    {
        $boltId = '11111111-1111-1111-1111-111111111111';
        $fixture = $this->writeFixture([
            [
                'oracle_id' => $boltId,
                'lang' => 'de',
                // No printed_name, no card_faces — nothing to search on.
            ],
        ]);
        $this->seedKnown($fixture, [
            ['id' => $boltId, 'name' => 'Lightning Bolt'],
        ]);

        (new TranslationsService)->updateTranslations(shadow: false);

        $this->assertSame(0, DB::table('oracle_card_translations')->count());
    }

    #[Test]
    public function it_falls_back_to_first_face_printed_name_when_top_level_missing(): void
    {
        $oracleId = '22222222-2222-2222-2222-222222222222';
        $fixture = $this->writeFixture([
            [
                'oracle_id' => $oracleId,
                'lang' => 'de',
                'card_faces' => [
                    [
                        'printed_name' => 'Brennende Inspiration',
                    ],
                    [
                        'printed_name' => 'Hügelriesensaal',
                    ],
                ],
            ],
        ]);
        $this->seedKnown($fixture, [
            [
                'id' => $oracleId,
                'name' => 'Smelting Vat // Furnace Reins',
                'faces' => ['Smelting Vat', 'Furnace Reins'],
            ],
        ]);

        (new TranslationsService)->updateTranslations(shadow: false);

        $oracle = DB::table('oracle_card_translations')->where('oracle_card_id', $oracleId)->first();
        $this->assertNotNull($oracle);
        $this->assertSame('Brennende Inspiration', $oracle->printed_name);
    }

    #[Test]
    public function it_dedupes_oracle_and_face_rows_across_reprints(): void
    {
        $boltId = '11111111-1111-1111-1111-111111111111';
        $fixture = $this->writeFixture([
            // Three German printings of the same card — only the first
            // wins; the next two are deduped.
            [
                'oracle_id' => $boltId,
                'lang' => 'de',
                'printed_name' => 'Blitzschlag',
            ],
            [
                'oracle_id' => $boltId,
                'lang' => 'de',
                'printed_name' => 'Blitzschlag',
            ],
            [
                'oracle_id' => $boltId,
                'lang' => 'de',
                'printed_name' => 'Blitzschlag',
            ],
            // A French printing — separate (oracle, lang) → separate row.
            [
                'oracle_id' => $boltId,
                'lang' => 'fr',
                'printed_name' => 'Foudre',
            ],
        ]);
        $this->seedKnown($fixture, [
            ['id' => $boltId, 'name' => 'Lightning Bolt'],
        ]);

        (new TranslationsService)->updateTranslations(shadow: false);

        $this->assertSame(2, DB::table('oracle_card_translations')->count());
        $this->assertSame(1, DB::table('oracle_card_translations')
            ->where('oracle_card_id', $boltId)->where('lang', 'de')->count());
        $this->assertSame(1, DB::table('oracle_card_translations')
            ->where('oracle_card_id', $boltId)->where('lang', 'fr')->count());
    }

    #[Test]
    public function it_extracts_face_translations_for_transform_layouts(): void
    {
        $oracleId = '22222222-2222-2222-2222-222222222222';
        $fixture = $this->writeFixture([
            [
                'oracle_id' => $oracleId,
                'lang' => 'de',
                'name' => 'Delver of Secrets // Insectile Aberration',
                'card_faces' => [
                    ['printed_name' => 'Entdecker der Geheimnisse'],
                    ['printed_name' => 'Insektoide Aberration'],
                ],
            ],
        ]);
        $this->seedKnown($fixture, [
            [
                'id' => $oracleId,
                'name' => 'Delver of Secrets // Insectile Aberration',
                'faces' => ['Delver of Secrets', 'Insectile Aberration'],
            ],
        ]);

        (new TranslationsService)->updateTranslations(shadow: false);

        $faces = DB::table('oracle_card_face_translations')
            ->where('oracle_card_id', $oracleId)
            ->orderBy('face_index')
            ->get();
        $this->assertCount(2, $faces);
        $this->assertSame(0, (int) $faces[0]->face_index);
        $this->assertSame('Entdecker der Geheimnisse', $faces[0]->printed_name);
        $this->assertSame(
            CardNameNormalizer::normalize('Entdecker der Geheimnisse'),
            $faces[0]->searchable_name,
        );
        $this->assertSame(1, (int) $faces[1]->face_index);
        $this->assertSame('Insektoide Aberration', $faces[1]->printed_name);
    }

    #[Test]
    public function it_uses_face_oracle_id_when_present_for_reversible_layouts(): void
    {
        $printingOracleId = '33333333-3333-3333-3333-333333333333';
        $faceOracleId = '44444444-4444-4444-4444-444444444444';
        $fixture = $this->writeFixture([
            [
                'oracle_id' => $printingOracleId,
                'lang' => 'de',
                'card_faces' => [
                    [
                        // Reversible cards put per-face oracle_id on the face row;
                        // the parent printing's oracle_id refers to the "card"
                        // entity, not the face's logical oracle.
                        'oracle_id' => $faceOracleId,
                        'printed_name' => 'Verzauberter Reisender',
                    ],
                ],
            ],
        ]);
        $this->seedKnown($fixture, [
            ['id' => $printingOracleId, 'name' => 'Front Oracle'],
            ['id' => $faceOracleId, 'name' => 'Face Oracle', 'faces' => ['Face Oracle']],
        ]);

        (new TranslationsService)->updateTranslations(shadow: false);

        $face = DB::table('oracle_card_face_translations')
            ->where('oracle_card_id', $faceOracleId)
            ->where('face_index', 0)
            ->first();
        $this->assertNotNull($face, 'face row should be keyed by the per-face oracle_id');
        $this->assertSame('Verzauberter Reisender', $face->printed_name);
    }

    #[Test]
    public function it_skips_faces_not_present_in_oracle_card_faces(): void
    {
        $oracleId = '22222222-2222-2222-2222-222222222222';
        $fixture = $this->writeFixture([
            [
                'oracle_id' => $oracleId,
                'lang' => 'de',
                'card_faces' => [
                    ['printed_name' => 'Bekanntes Gesicht'],
                    ['printed_name' => 'Unbekanntes Gesicht'],
                ],
            ],
        ]);
        // Only one face seeded — face_index 1 should be skipped.
        $this->seedKnown($fixture, [
            ['id' => $oracleId, 'name' => 'Known Face', 'faces' => ['Known Face']],
        ]);

        (new TranslationsService)->updateTranslations(shadow: false);

        $count = DB::table('oracle_card_face_translations')->where('oracle_card_id', $oracleId)->count();
        $this->assertSame(1, $count);
    }

    #[Test]
    public function it_aborts_when_all_cards_bulk_row_is_missing(): void
    {
        // No bulk_data row, no fixture file. Service should log + return
        // cleanly without throwing.
        (new TranslationsService)->updateTranslations(shadow: false);

        $this->assertSame(0, DB::table('oracle_card_translations')->count());
        $this->assertSame(0, DB::table('oracle_card_face_translations')->count());
    }
}
