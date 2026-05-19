<?php

namespace Tests\Feature;

use App\Models\OracleCard;
use App\Services\OracleNameSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for {@see OracleNameSearch::resolveMatchedTranslations}.
 *
 * The helper drives the "matched by translated name" badge on
 * card-search results. Search-side wiring is exercised by the
 * Services-suite (mysql + real Scryfall data); these tests pin the
 * pure helper logic in SQLite where seeding is cheap.
 */
class OracleNameSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hard-skip on real MariaDB connections. RefreshDatabase would
     * wipe the live Scryfall dataset; see DeckCardCardStackPivotTest
     * for the long-form rationale.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }
    }

    private function makeOracle(string $name, string $searchable): OracleCard
    {
        return OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => $searchable,
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => 'R',
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);
    }

    private function insertOracleTranslation(string $oracleId, string $lang, string $printed, string $searchable): void
    {
        DB::table(OracleNameSearch::ORACLE_TRANSLATION_TABLE)->insert([
            'oracle_card_id' => $oracleId,
            'lang' => $lang,
            'printed_name' => $printed,
            'searchable_name' => $searchable,
        ]);
    }

    private function insertFaceTranslation(string $oracleId, string $lang, string $printed, string $searchable): void
    {
        DB::table(OracleNameSearch::FACE_TRANSLATION_TABLE)->insert([
            'oracle_card_id' => $oracleId,
            'lang' => $lang,
            'face_index' => 0,
            'printed_name' => $printed,
            'searchable_name' => $searchable,
        ]);
    }

    #[Test]
    public function silent_when_english_already_matches_every_segment(): void
    {
        // "Blitzball Stadium" — English searchable_name contains "blitz",
        // so no translation badge should be surfaced even if a (junk)
        // DE row also matches.
        $oracle = $this->makeOracle('Blitzball Stadium', 'blitzballstadium');
        $this->insertOracleTranslation($oracle->id, 'de', 'Donnerball-Arena', 'donnerballarena');

        $result = OracleNameSearch::resolveMatchedTranslations(
            [$oracle->id => $oracle->searchable_name],
            ['blitz'],
            'de',
        );

        $this->assertSame([], $result);
    }

    #[Test]
    public function returns_translation_when_english_did_not_match(): void
    {
        // "Aether Flash" — English doesn't contain "blitz"; DE "Ätherblitz"
        // does. The badge should surface DE.
        $oracle = $this->makeOracle('Aether Flash', 'aetherflash');
        $this->insertOracleTranslation($oracle->id, 'de', 'Ätherblitz', 'atherblitz');

        $result = OracleNameSearch::resolveMatchedTranslations(
            [$oracle->id => $oracle->searchable_name],
            ['blitz'],
            'de',
        );

        $this->assertSame(
            [$oracle->id => ['lang' => 'de', 'name' => 'Ätherblitz']],
            $result,
        );
    }

    #[Test]
    public function picks_preferred_lang_when_multiple_translations_match(): void
    {
        // Both DE and FR rows would match an artificial segment that's
        // present in both — the preferred lang should win.
        $oracle = $this->makeOracle('Test Card', 'testcard');
        $this->insertOracleTranslation($oracle->id, 'de', 'DEnameZZZ', 'denamezzz');
        $this->insertOracleTranslation($oracle->id, 'fr', 'FRnameZZZ', 'frnamezzz');

        $preferDe = OracleNameSearch::resolveMatchedTranslations(
            [$oracle->id => $oracle->searchable_name],
            ['zzz'],
            'de',
        );
        $this->assertSame(['lang' => 'de', 'name' => 'DEnameZZZ'], $preferDe[$oracle->id]);

        $preferFr = OracleNameSearch::resolveMatchedTranslations(
            [$oracle->id => $oracle->searchable_name],
            ['zzz'],
            'fr',
        );
        $this->assertSame(['lang' => 'fr', 'name' => 'FRnameZZZ'], $preferFr[$oracle->id]);
    }

    #[Test]
    public function falls_back_to_searchable_langs_order_when_preferred_lang_did_not_match(): void
    {
        // Only FR matches; user prefers DE. The result should still
        // surface FR (the only coherent match), not silence the row.
        $oracle = $this->makeOracle('Test Card', 'testcard');
        $this->insertOracleTranslation($oracle->id, 'fr', 'FRnameZZZ', 'frnamezzz');

        $result = OracleNameSearch::resolveMatchedTranslations(
            [$oracle->id => $oracle->searchable_name],
            ['zzz'],
            'de',
        );

        $this->assertSame(['lang' => 'fr', 'name' => 'FRnameZZZ'], $result[$oracle->id]);
    }

    #[Test]
    public function multi_segment_requires_single_translation_to_match_all(): void
    {
        // DE row matches segment "blitz" but not "feuer".
        // FR row matches segment "feuer" but not "blitz". (Contrived,
        // but exercises the AND-across-segments-within-one-row rule.)
        // No single translation explains BOTH segments, so the helper
        // should fall through to silent.
        $oracle = $this->makeOracle('Aether Flash', 'aetherflash');
        $this->insertOracleTranslation($oracle->id, 'de', 'Blitzname', 'blitzname');
        $this->insertOracleTranslation($oracle->id, 'fr', 'Feuername', 'feuername');

        $result = OracleNameSearch::resolveMatchedTranslations(
            [$oracle->id => $oracle->searchable_name],
            ['blitz', 'feuer'],
            'de',
        );

        $this->assertSame([], $result);
    }

    #[Test]
    public function ignores_non_allowlisted_languages(): void
    {
        // Phyrexian / Latin etc. are excluded from SEARCHABLE_LANGS by
        // design. A `ph`-only match should silence the row.
        $oracle = $this->makeOracle('Aether Flash', 'aetherflash');
        $this->insertOracleTranslation($oracle->id, 'ph', 'Blitzph', 'blitzph');

        $result = OracleNameSearch::resolveMatchedTranslations(
            [$oracle->id => $oracle->searchable_name],
            ['blitz'],
            null,
        );

        $this->assertSame([], $result);
    }

    #[Test]
    public function face_translation_table_is_also_searched(): void
    {
        // No oracle-level translation; the match lives on a face row.
        $oracle = $this->makeOracle('Transform Card', 'transformcard');
        $this->insertFaceTranslation($oracle->id, 'de', 'Frontblitz', 'frontblitz');

        $result = OracleNameSearch::resolveMatchedTranslations(
            [$oracle->id => $oracle->searchable_name],
            ['blitz'],
            'de',
        );

        $this->assertSame(['lang' => 'de', 'name' => 'Frontblitz'], $result[$oracle->id]);
    }

    #[Test]
    public function empty_inputs_short_circuit(): void
    {
        $this->assertSame([], OracleNameSearch::resolveMatchedTranslations([], ['blitz'], 'de'));
        $oracle = $this->makeOracle('Aether Flash', 'aetherflash');
        $this->assertSame(
            [],
            OracleNameSearch::resolveMatchedTranslations([$oracle->id => $oracle->searchable_name], [], 'de'),
        );
    }

    #[Test]
    public function available_langs_empty_input_short_circuits(): void
    {
        $this->assertSame([], OracleNameSearch::availableLangsByOracle([]));
    }

    #[Test]
    public function available_langs_returns_dedupe_across_oracle_and_face_tables(): void
    {
        // Oracle row in DE + face row in DE (same lang, different tables)
        // should collapse to one DE entry; FR only on the face table
        // should still surface. Allowlisted langs only.
        $oracle = $this->makeOracle('Test Card', 'testcard');
        $this->insertOracleTranslation($oracle->id, 'de', 'Testkarte', 'testkarte');
        $this->insertFaceTranslation($oracle->id, 'de', 'Testkarte (Vorderseite)', 'testkarte');
        $this->insertFaceTranslation($oracle->id, 'fr', 'Carte de test', 'cartedetest');

        $result = OracleNameSearch::availableLangsByOracle([$oracle->id]);

        $this->assertSame(['de', 'fr'], $result[$oracle->id]);
    }

    #[Test]
    public function available_langs_filters_non_allowlisted_langs(): void
    {
        // Phyrexian is off the allowlist and must be dropped.
        $oracle = $this->makeOracle('Phyrexian Card', 'phyrexiancard');
        $this->insertOracleTranslation($oracle->id, 'de', 'DEname', 'dename');
        $this->insertOracleTranslation($oracle->id, 'ph', 'Phname', 'phname');

        $result = OracleNameSearch::availableLangsByOracle([$oracle->id]);

        $this->assertSame(['de'], $result[$oracle->id]);
    }

    #[Test]
    public function available_langs_sorts_by_searchable_langs_order(): void
    {
        // Inserted in reverse-of-SEARCHABLE_LANGS order; the returned
        // list should still respect SEARCHABLE_LANGS ordering so the
        // picker is deterministic.
        $oracle = $this->makeOracle('Multilingual Card', 'multilingualcard');
        $this->insertOracleTranslation($oracle->id, 'ru', 'RUname', 'runame');
        $this->insertOracleTranslation($oracle->id, 'ja', 'JAname', 'janame');
        $this->insertOracleTranslation($oracle->id, 'de', 'DEname', 'dename');

        $result = OracleNameSearch::availableLangsByOracle([$oracle->id]);

        $this->assertSame(['de', 'ja', 'ru'], $result[$oracle->id]);
    }

    #[Test]
    public function available_langs_supports_multi_oracle_batch(): void
    {
        $a = $this->makeOracle('Card A', 'carda');
        $b = $this->makeOracle('Card B', 'cardb');
        $c = $this->makeOracle('Card C', 'cardc');
        $this->insertOracleTranslation($a->id, 'de', 'A_DE', 'a_de');
        $this->insertOracleTranslation($b->id, 'fr', 'B_FR', 'b_fr');
        // c has no translation rows

        $result = OracleNameSearch::availableLangsByOracle([$a->id, $b->id, $c->id]);

        $this->assertSame(['de'], $result[$a->id]);
        $this->assertSame(['fr'], $result[$b->id]);
        $this->assertArrayNotHasKey($c->id, $result);
    }
}
