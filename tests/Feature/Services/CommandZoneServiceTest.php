<?php

namespace Tests\Feature\Services;

use App\Enums\CardFormat;
use App\Services\CardSearchParser;
use App\Services\CommandZoneService;
use App\Services\Scryfall\TranslationsService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Read-only integration coverage for {@see CommandZoneService}.
 *
 * Runs against the real application database so the commander /
 * oathbreaker pickers can be exercised against actual Scryfall data
 * including foreign-language translations imported by
 * {@see TranslationsService}.
 *
 * Skips automatically on the in-memory SQLite default — see
 * {@see DeckCardSearchServiceTest} for the wider rationale.
 *
 *     composer test:mysql -- --filter=CommandZoneServiceTest
 */
class CommandZoneServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'CommandZoneService tests require MariaDB + a Scryfall-populated dataset.'
            );
        }
    }

    /**
     * @return array{rule0: bool, partner: bool, friends_forever: bool, doctors_companion: bool, background: bool, partner_type: string|null, exclude: string|null}
     */
    private function defaultFilters(): array
    {
        return [
            'rule0' => false,
            'partner' => false,
            'friends_forever' => false,
            'doctors_companion' => false,
            'background' => false,
            'partner_type' => null,
            'exclude' => null,
        ];
    }

    // ── Commanders search ──────────────────────────────────────────────────

    #[Test]
    public function commanders_english_query_finds_atraxa(): void
    {
        // Regression: English search must still resolve the obvious case.
        // Atraxa, Praetors' Voice is a 4-color legendary creature and a
        // famously popular commander, so it's a safe bedrock fixture.
        $parsed = CardSearchParser::parse('atraxa');
        $this->assertNotNull($parsed);

        $results = CommandZoneService::searchCommanders(
            CardFormat::Commander,
            $parsed,
            $this->defaultFilters(),
        );

        $names = $results->pluck('name')->all();
        $this->assertContains("Atraxa, Praetors' Voice", $names);
    }

    /**
     * Atraxa, Praetors' Voice → German "Atraxa, Stimme der Prätoren".
     * The service should join through oracle_card_translations and
     * resolve the English oracle for the picker.
     */
    #[Test]
    public function commanders_finds_card_by_german_printed_name(): void
    {
        $parsed = CardSearchParser::parse('Prätoren');
        $this->assertNotNull($parsed);

        $results = CommandZoneService::searchCommanders(
            CardFormat::Commander,
            $parsed,
            $this->defaultFilters(),
        );

        $names = $results->pluck('name')->all();
        $this->assertContains("Atraxa, Praetors' Voice", $names);
    }

    /**
     * Non-commanders (Lightning Bolt is an instant) must not surface
     * even when their German translation matches the query token —
     * the in-PHP commander qualification filter runs after the SQL
     * join and rejects non-legendary-creature candidates.
     */
    #[Test]
    public function commanders_german_query_does_not_surface_non_commanders(): void
    {
        $parsed = CardSearchParser::parse('Blitzschlag');
        $this->assertNotNull($parsed);

        $results = CommandZoneService::searchCommanders(
            CardFormat::Commander,
            $parsed,
            $this->defaultFilters(),
        );

        $names = $results->pluck('name')->all();
        $this->assertNotContains('Lightning Bolt', $names);
    }

    // ── Oathbreaker search ─────────────────────────────────────────────────

    /**
     * Sanity check: the English path picks up a planeswalker.
     * "Teferi, Hero of Dominaria" is a 3-cmc planeswalker, legal in
     * Oathbreaker.
     */
    #[Test]
    public function oathbreaker_english_query_finds_teferi(): void
    {
        $parsed = CardSearchParser::parse('teferi hero');
        $this->assertNotNull($parsed);

        $results = CommandZoneService::searchOathbreaker(
            CardFormat::Oathbreaker,
            $parsed,
            'planeswalker',
            null,
            false,
            null,
        );

        $names = $results->pluck('name')->all();
        $this->assertContains('Teferi, Hero of Dominaria', $names);
    }

    /**
     * Foreign-language search reaches the oathbreaker picker too —
     * Lightning Bolt is in the translation tables but isn't a
     * planeswalker, so the PHP filter rejects it. This pins the
     * regression check that the OR doesn't accidentally let non-
     * planeswalkers slip through.
     */
    #[Test]
    public function oathbreaker_german_query_does_not_surface_non_planeswalkers(): void
    {
        $parsed = CardSearchParser::parse('Blitzschlag');
        $this->assertNotNull($parsed);

        $results = CommandZoneService::searchOathbreaker(
            CardFormat::Oathbreaker,
            $parsed,
            'planeswalker',
            null,
            false,
            null,
        );

        $names = $results->pluck('name')->all();
        $this->assertNotContains('Lightning Bolt', $names);
    }
}
