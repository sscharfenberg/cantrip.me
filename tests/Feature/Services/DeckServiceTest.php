<?php

namespace Tests\Feature\Services;

use App\Enums\CardFormat;
use App\Models\OracleCard;
use App\Services\DeckService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Read-only integration tests for {@see DeckService}'s command-zone
 * validation helpers.
 *
 * These tests cover the exact regression that crashed deck creation for
 * partner commanders (Yoshimaru + Rograkh): `DeckService::parsedForCardName`
 * was hand-rolling a `$parsed` array missing the `normalized_name_segments`
 * key, which `CommandZoneService` reads unconditionally since the normalizer
 * refactor.
 *
 * Like {@see DeckCardSearchServiceTest} these tests:
 *
 *  - Never write to the database — they only `find()` real oracle cards.
 *  - Skip automatically on non-Postgres connections (regex-based filtering
 *    is unsupported on SQLite).
 *  - Assert on bedrock Magic cards guaranteed to exist in any Scryfall-synced
 *    dataset (Atraxa, Yoshimaru, Rograkh, Teferi, Counterspell, etc.).
 *
 * To run on staging:
 *
 *     composer test:pgsql -- --filter=DeckServiceTest
 */
class DeckServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'DeckService validation requires Postgres (regex color-identity filter). '
                .'Run on staging with composer test:pgsql.'
            );
        }
    }

    /**
     * Look up a real oracle id by card name — avoids hardcoding UUIDs so the
     * tests remain stable across Scryfall re-imports.
     */
    private function oracleIdByName(string $name): string
    {
        $id = OracleCard::where('name', $name)->value('id');

        $this->assertNotNull($id, "Oracle card '{$name}' must exist in the staging database.");

        return $id;
    }

    // ---------- isLegalCommander ----------

    #[Test]
    public function is_legal_commander_accepts_plain_legendary_creature(): void
    {
        $id = $this->oracleIdByName("Atraxa, Praetors' Voice");

        $this->assertTrue(DeckService::isLegalCommander($id, CardFormat::Commander));
    }

    #[Test]
    public function is_legal_commander_accepts_partner_commander_yoshimaru(): void
    {
        // Regression guard: Yoshimaru has a comma in its name which must be
        // normalized away before CommandZoneService can match it against
        // `searchable_name`.
        $id = $this->oracleIdByName('Yoshimaru, Ever Faithful');

        $this->assertTrue(DeckService::isLegalCommander($id, CardFormat::Commander));
    }

    #[Test]
    public function is_legal_commander_accepts_partner_commander_rograkh(): void
    {
        $id = $this->oracleIdByName('Rograkh, Son of Rohgahh');

        $this->assertTrue(DeckService::isLegalCommander($id, CardFormat::Commander));
    }

    #[Test]
    public function is_legal_commander_rejects_non_legendary_card(): void
    {
        $id = $this->oracleIdByName('Lightning Bolt');

        $this->assertFalse(DeckService::isLegalCommander($id, CardFormat::Commander));
    }

    #[Test]
    public function is_legal_commander_rejects_banned_card(): void
    {
        // Black Lotus is an artifact AND banned in Commander — both checks
        // would reject it; the test exists to document the legality path.
        $id = $this->oracleIdByName('Black Lotus');

        $this->assertFalse(DeckService::isLegalCommander($id, CardFormat::Commander));
    }

    #[Test]
    public function is_legal_commander_rejects_missing_oracle_id(): void
    {
        $this->assertFalse(
            DeckService::isLegalCommander('00000000-0000-0000-0000-000000000000', CardFormat::Commander)
        );
    }

    // ---------- isLegalCompanion ----------

    #[Test]
    public function is_legal_companion_accepts_valid_partner_pair(): void
    {
        // The exact pair that caused the original production crash.
        $yoshimaru = $this->oracleIdByName('Yoshimaru, Ever Faithful');
        $rograkh = $this->oracleIdByName('Rograkh, Son of Rohgahh');

        $this->assertTrue(
            DeckService::isLegalCompanion($rograkh, $yoshimaru, CardFormat::Commander)
        );
    }

    #[Test]
    public function is_legal_companion_rejects_companion_for_non_partner_commander(): void
    {
        // Atraxa has no partner / companion type, so nothing can pair with it.
        $atraxa = $this->oracleIdByName("Atraxa, Praetors' Voice");
        $rograkh = $this->oracleIdByName('Rograkh, Son of Rohgahh');

        $this->assertFalse(
            DeckService::isLegalCompanion($rograkh, $atraxa, CardFormat::Commander)
        );
    }

    // ---------- isLegalOathbreaker ----------

    #[Test]
    public function is_legal_oathbreaker_accepts_planeswalker(): void
    {
        $id = $this->oracleIdByName('Teferi, Temporal Archmage');

        $this->assertTrue(DeckService::isLegalOathbreaker($id, CardFormat::Oathbreaker));
    }

    #[Test]
    public function is_legal_oathbreaker_rejects_non_planeswalker(): void
    {
        $id = $this->oracleIdByName('Counterspell');

        $this->assertFalse(DeckService::isLegalOathbreaker($id, CardFormat::Oathbreaker));
    }

    // ---------- isLegalSignatureSpell ----------

    #[Test]
    public function is_legal_signature_spell_accepts_spell_within_planeswalker_ci(): void
    {
        $teferi = $this->oracleIdByName('Teferi, Temporal Archmage'); // CI: U
        $counterspell = $this->oracleIdByName('Counterspell');        // CI: U

        $this->assertTrue(
            DeckService::isLegalSignatureSpell($counterspell, $teferi, CardFormat::Oathbreaker)
        );
    }

    #[Test]
    public function is_legal_signature_spell_rejects_spell_outside_planeswalker_ci(): void
    {
        $teferi = $this->oracleIdByName('Teferi, Temporal Archmage'); // CI: U
        $bolt = $this->oracleIdByName('Lightning Bolt');              // CI: R

        $this->assertFalse(
            DeckService::isLegalSignatureSpell($bolt, $teferi, CardFormat::Oathbreaker)
        );
    }

    #[Test]
    public function is_legal_signature_spell_rejects_non_instant_or_sorcery(): void
    {
        // Sol Ring is an artifact — not an instant or sorcery, so it can
        // never be a signature spell, even though it's colorless (subset of
        // any planeswalker CI).
        $teferi = $this->oracleIdByName('Teferi, Temporal Archmage');
        $solRing = $this->oracleIdByName('Sol Ring');

        $this->assertFalse(
            DeckService::isLegalSignatureSpell($solRing, $teferi, CardFormat::Oathbreaker)
        );
    }
}
