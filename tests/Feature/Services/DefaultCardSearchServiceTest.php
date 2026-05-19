<?php

namespace Tests\Feature\Services;

use App\Services\CardSearchParser;
use App\Services\DefaultCardSearchService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Read-only integration tests for {@see DefaultCardSearchService}.
 *
 * Runs against the real application database so the two-phase strategy can
 * be exercised against actual Scryfall data. Skips automatically on the
 * in-memory SQLite default — see DeckCardSearchServiceTest for the wider
 * rationale.
 *
 *     composer test:mysql -- --filter=DefaultCardSearchServiceTest
 */
class DefaultCardSearchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'DefaultCardSearchService tests require MariaDB + a Scryfall-populated dataset.'
            );
        }
    }

    /**
     * Convenience: parse + buildQuery + orderAndFetch + return mapped rows.
     *
     * @return array<int, object>
     */
    private function search(string $query, array $columns = ['default_cards.id', 'default_cards.name AS card_name', 'sets.code AS set_code', 'default_cards.collector_number']): array
    {
        $parsed = CardSearchParser::parse($query);
        $this->assertNotNull($parsed, "Parser returned null for query: $query");

        $base = DefaultCardSearchService::buildQuery($parsed);
        $this->assertNotNull($base, "buildQuery returned null for query: $query");

        return DefaultCardSearchService::orderAndFetch($base, $parsed['normalized_name_segments'], $columns)->all();
    }

    /**
     * Regression: `set:ice ill` used to miss "Illusions of Grandeur" because
     * the alphabetical phase-1 LIMIT cut off before reaching it. Pushing
     * `set:` into phase 1 keeps the candidate pool bounded to the set's
     * ~400 printings, well under the 200-oracle cap.
     */
    #[Test]
    public function set_filter_in_phase_1_finds_illusions_of_grandeur(): void
    {
        $rows = $this->search('set:ice ill');

        $names = array_map(fn ($r) => $r->card_name, $rows);

        $this->assertContains('Illusions of Grandeur', $names);
        // Every returned printing must actually be from Ice Age — the set:
        // filter is still re-applied in phase 2 to narrow printings.
        foreach ($rows as $row) {
            $this->assertSame('ice', $row->set_code, "Row {$row->card_name} is not from set:ice");
        }
    }

    /**
     * `set:ice cn:79 ill` should resolve to exactly the Ice Age printing of
     * Illusions of Grandeur (cn 79) and nothing else.
     */
    #[Test]
    public function set_and_cn_combined_in_phase_1_pins_to_one_printing(): void
    {
        $rows = $this->search('set:ice cn:79 ill');

        $this->assertCount(1, $rows);
        $this->assertSame('Illusions of Grandeur', $rows[0]->card_name);
        $this->assertSame('ice', $rows[0]->set_code);
        $this->assertSame('79', $rows[0]->collector_number);
    }

    /**
     * Pure `set:ice` (no name segments) skips phase 1 entirely and queries
     * default_cards directly via the indexed set_id filter. Should return
     * the full Ice Age print run.
     */
    #[Test]
    public function pure_set_filter_returns_set_printings(): void
    {
        $rows = $this->search('set:ice');

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('ice', $row->set_code);
        }
    }

    /**
     * Parser yields null for too-short queries; no filters means
     * buildQuery should never run against an empty payload.
     */
    #[Test]
    public function parser_rejects_short_queries(): void
    {
        $this->assertNull(CardSearchParser::parse('a'));
        $this->assertNull(CardSearchParser::parse(''));
    }

    /**
     * A name that matches no oracles short-circuits phase 1 and returns null
     * from buildQuery — the controller maps this to an empty response.
     */
    #[Test]
    public function unmatched_name_returns_null_query(): void
    {
        $parsed = CardSearchParser::parse('zzzzzzzzznotacard');
        $this->assertNotNull($parsed);

        $this->assertNull(DefaultCardSearchService::buildQuery($parsed));
    }

    // ── Foreign-language search ────────────────────────────────────────────

    /**
     * "Blitzschlag" is the German printed name of Lightning Bolt. The
     * phase-1 oracle prefilter ORs against oracle_card_translations, so
     * the German query should surface the English oracle and its
     * printings.
     */
    #[Test]
    public function phase_1_resolves_german_printed_name(): void
    {
        $rows = $this->search('Blitzschlag');

        $names = array_map(fn ($r) => $r->card_name, $rows);
        $this->assertContains('Lightning Bolt', $names);
    }

    /**
     * "Foudre" is the French printed name of Lightning Bolt.
     */
    #[Test]
    public function phase_1_resolves_french_printed_name(): void
    {
        $rows = $this->search('Foudre');

        $names = array_map(fn ($r) => $r->card_name, $rows);
        $this->assertContains('Lightning Bolt', $names);
    }

    /**
     * Sanity: the English query path keeps the previous behavior. The
     * translation OR adds matches but must not displace exact English
     * hits.
     */
    #[Test]
    public function english_query_regression_still_finds_card(): void
    {
        $rows = $this->search('lightning bolt');

        $names = array_map(fn ($r) => $r->card_name, $rows);
        $this->assertContains('Lightning Bolt', $names);
    }
}
