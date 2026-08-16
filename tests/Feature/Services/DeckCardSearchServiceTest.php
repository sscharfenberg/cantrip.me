<?php

namespace Tests\Feature\Services;

use App\Enums\CardFormat;
use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\OracleCard;
use App\Rulebreakers\RulebreakerRegistry;
use App\Services\DeckCardSearchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Read-only integration tests for {@see DeckCardSearchService}.
 *
 * These tests run against the real application database so they can exercise
 * `REGEXP` color-identity filtering (unsupported on SQLite) and verify ranking
 * against real card data. They:
 *
 *  - Never write to the database — only non-persisted `Deck` instances are used.
 *  - Skip automatically on non-MariaDB connections (e.g. the default in-memory
 *    SQLite used by local `composer test`).
 *  - Assert on bedrock Magic cards (Sol Ring, Lightning Bolt, Counterspell,
 *    Black Lotus) that are guaranteed to exist in any Scryfall-synced dataset.
 *
 * To run on staging (where `.env` points at the real MariaDB):
 *
 *     composer test:mysql -- --filter=DeckCardSearchServiceTest
 *
 * The composer script injects `DB_CONNECTION=mysql` / `DB_DATABASE=mbos` via
 * `@putenv` so they beat PHPUnit's non-forced `<env>` tags and Laravel's
 * config picks them up.
 */
class DeckCardSearchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'DeckCardSearchService requires MariaDB (REGEXP color-identity filter). '
                .'Run against staging with DB_CONNECTION=mysql.'
            );
        }
    }

    /**
     * Build a Deck instance without persisting it. The service only reads
     * `$deck->format` and `$deck->colors`, so an in-memory model is enough.
     */
    private function makeDeck(CardFormat $format, ?string $colors = null): Deck
    {
        return new Deck([
            'format' => $format,
            'colors' => $colors,
        ]);
    }

    // ── Oracle path ────────────────────────────────────────────────────────

    #[Test]
    public function oracle_returns_empty_array_for_too_short_query(): void
    {
        $results = DeckCardSearchService::searchOracleForDeck(
            $this->makeDeck(CardFormat::Commander),
            'a'
        );

        $this->assertSame([], $results);
    }

    #[Test]
    public function oracle_path_finds_sol_ring_in_commander(): void
    {
        $results = DeckCardSearchService::searchOracleForDeck(
            $this->makeDeck(CardFormat::Commander),
            'sol ring'
        );

        $this->assertNotEmpty($results);
        $this->assertSame('Sol Ring', $results[0]['name']);
        $this->assertArrayHasKey('oracle_id', $results[0]);
        $this->assertArrayHasKey('printing', $results[0]);
        $this->assertNotNull($results[0]['printing']);
        $this->assertArrayHasKey('card_image_0', $results[0]['printing']);
    }

    #[Test]
    public function oracle_ranks_exact_match_above_contains_match(): void
    {
        // 5-color Commander deck ('WUBRG') so the CI filter doesn't exclude
        // blue cards — this test is about ranking, not color identity.
        $results = DeckCardSearchService::searchOracleForDeck(
            $this->makeDeck(CardFormat::Commander, 'WUBRG'),
            'counterspell'
        );

        $this->assertNotEmpty($results);
        $this->assertSame('Counterspell', $results[0]['name']);
    }

    #[Test]
    public function oracle_multi_segment_query_requires_all_segments(): void
    {
        $results = DeckCardSearchService::searchOracleForDeck(
            $this->makeDeck(CardFormat::Commander, 'WUBRG'),
            'lightning bolt'
        );

        $this->assertNotEmpty($results);
        $this->assertSame('Lightning Bolt', $results[0]['name']);

        // Every result must contain both segments in its name.
        foreach ($results as $card) {
            $lower = mb_strtolower($card['name']);
            $this->assertStringContainsString('lightning', $lower);
            $this->assertStringContainsString('bolt', $lower);
        }
    }

    #[Test]
    public function oracle_normalizes_accents_and_apostrophes(): void
    {
        // "Lim-Dul's Vault" (no accent, plain apostrophe) should still find
        // "Lim-Dûl's Vault" via the searchable_name normalizer.
        $results = DeckCardSearchService::searchOracleForDeck(
            $this->makeDeck(CardFormat::Commander, 'WUBRG'),
            "Lim-Dul's Vault"
        );

        $this->assertNotEmpty($results);
        $names = array_column($results, 'name');
        $this->assertContains("Lim-Dûl's Vault", $names);
    }

    #[Test]
    public function oracle_color_identity_excludes_out_of_identity_cards(): void
    {
        // Mono-white Commander deck must not return Lightning Bolt (red).
        $deck = $this->makeDeck(CardFormat::Commander, 'W');
        $results = DeckCardSearchService::searchOracleForDeck($deck, 'lightning bolt');

        $names = array_column($results, 'name');
        $this->assertNotContains('Lightning Bolt', $names);
    }

    #[Test]
    public function oracle_color_identity_allows_colorless_cards(): void
    {
        // Sol Ring is colorless — every color identity should include it.
        $deck = $this->makeDeck(CardFormat::Commander, 'W');
        $results = DeckCardSearchService::searchOracleForDeck($deck, 'sol ring');

        $this->assertNotEmpty($results);
        $this->assertSame('Sol Ring', $results[0]['name']);
    }

    #[Test]
    public function oracle_format_without_color_identity_enforcement_ignores_deck_colors(): void
    {
        // Modern does not enforce color identity — deck->colors is irrelevant.
        $deck = $this->makeDeck(CardFormat::Modern, 'W');
        $results = DeckCardSearchService::searchOracleForDeck($deck, 'lightning bolt');

        $this->assertNotEmpty($results);
        $this->assertSame('Lightning Bolt', $results[0]['name']);
    }

    #[Test]
    public function oracle_legality_filter_excludes_banned_cards_from_commander(): void
    {
        // Black Lotus is banned in Commander.
        $deck = $this->makeDeck(CardFormat::Commander);
        $results = DeckCardSearchService::searchOracleForDeck($deck, 'black lotus');

        $names = array_column($results, 'name');
        $this->assertNotContains('Black Lotus', $names);
    }

    #[Test]
    public function oracle_legality_filter_includes_legal_cards_in_vintage(): void
    {
        // Black Lotus is restricted (but legal) in Vintage.
        $deck = $this->makeDeck(CardFormat::Vintage);
        $results = DeckCardSearchService::searchOracleForDeck($deck, 'black lotus');

        $names = array_column($results, 'name');
        $this->assertContains('Black Lotus', $names);
    }

    #[Test]
    public function oracle_path_ignores_set_and_cn_tokens(): void
    {
        // The oracle path is name-by-card, not by printing. `set:lea` and
        // `cn:269` must be stripped by the parser and not filter the result.
        $deck = $this->makeDeck(CardFormat::Vintage);
        $results = DeckCardSearchService::searchOracleForDeck(
            $deck,
            'sol ring set:lea cn:269'
        );

        $this->assertNotEmpty($results);
        $this->assertSame('Sol Ring', $results[0]['name']);
        // Oracle path returns distinct oracle cards — there should be exactly
        // one Sol Ring entry, and its resolved newest printing is NOT pinned
        // to LEA (newest released set wins).
        $solRings = array_filter($results, fn (array $c): bool => $c['name'] === 'Sol Ring');
        $this->assertCount(1, $solRings);
    }

    #[Test]
    public function oracle_result_shape_matches_contract(): void
    {
        $results = DeckCardSearchService::searchOracleForDeck(
            $this->makeDeck(CardFormat::Commander),
            'sol ring'
        );

        $this->assertNotEmpty($results);
        $card = $results[0];

        $this->assertArrayHasKey('oracle_id', $card);
        $this->assertArrayHasKey('name', $card);
        $this->assertArrayHasKey('cmc', $card);
        $this->assertArrayHasKey('color_identity', $card);
        $this->assertArrayHasKey('printing', $card);
        $this->assertIsFloat($card['cmc']);

        $printing = $card['printing'];
        $this->assertArrayHasKey('id', $printing);
        $this->assertArrayHasKey('card_image_0', $printing);
        $this->assertArrayHasKey('card_image_1', $printing);
        $this->assertArrayHasKey('set_code', $printing);
        $this->assertArrayHasKey('collector_number', $printing);
    }

    // ── Printings path ─────────────────────────────────────────────────────

    #[Test]
    public function printings_returns_empty_array_for_too_short_query(): void
    {
        $results = DeckCardSearchService::searchPrintingsForDeck(
            $this->makeDeck(CardFormat::Commander),
            'a'
        );

        $this->assertSame([], $results);
    }

    #[Test]
    public function printings_set_token_pins_results_to_set(): void
    {
        // `set:lea` pins the results to Limited Edition Alpha printings.
        $deck = $this->makeDeck(CardFormat::Vintage);
        $results = DeckCardSearchService::searchPrintingsForDeck($deck, 'sol ring set:lea');

        $this->assertNotEmpty($results);
        foreach ($results as $card) {
            $this->assertSame('lea', $card['printing']['set']['code']);
        }
        $this->assertSame('Sol Ring', $results[0]['name']);
    }

    #[Test]
    public function printings_cn_token_filters_to_specific_collector_number(): void
    {
        // Sol Ring in LEA is collector number 269.
        $deck = $this->makeDeck(CardFormat::Vintage);
        $results = DeckCardSearchService::searchPrintingsForDeck(
            $deck,
            'sol ring set:lea cn:269'
        );

        $this->assertNotEmpty($results);
        $this->assertSame('269', $results[0]['printing']['cn']);
        $this->assertSame('lea', $results[0]['printing']['set']['code']);
        $this->assertSame('Sol Ring', $results[0]['name']);
    }

    #[Test]
    public function printings_cn_token_alone_returns_matches_across_sets(): void
    {
        // Token-only query (no name segment): `cn:269` should return many
        // cn=269 printings from across the catalogue. Regression: phase 1's
        // 200-oracle prefilter used to ignore set:/cn: filters, so a cn-only
        // query returned the first 200 oracles in arbitrary order and only
        // the few that happened to have a cn=269 printing survived phase 2.
        $deck = $this->makeDeck(CardFormat::Vintage);
        $results = DeckCardSearchService::searchPrintingsForDeck($deck, 'cn:269');

        $this->assertNotEmpty($results);
        foreach ($results as $card) {
            $this->assertSame('269', $card['printing']['cn']);
        }
        // The fix should surface printings from multiple sets — pre-fix the
        // result was clamped to whatever 1–2 sets the unfiltered phase 1
        // happened to include.
        $setCodes = array_unique(array_map(
            fn (array $r) => $r['printing']['set']['code'],
            $results,
        ));
        $this->assertGreaterThan(2, count($setCodes));
    }

    #[Test]
    public function printings_cn_token_alone_works_for_non_commander_format(): void
    {
        // Non-commander format: color identity isn't enforced but legality is.
        // `cn:1` should still return cn=1 printings from Modern-legal sets.
        // Regression: pre-fix this returned an empty array because the
        // smaller Modern-legal pool meant the 200-oracle prefilter was
        // statistically unlikely to include any oracle with a cn=1 printing.
        $deck = $this->makeDeck(CardFormat::Modern);
        $results = DeckCardSearchService::searchPrintingsForDeck($deck, 'cn:1');

        $this->assertNotEmpty($results);
        foreach ($results as $card) {
            $this->assertSame('1', $card['printing']['cn']);
        }
    }

    #[Test]
    public function printings_cn_token_with_include_non_legal_returns_results(): void
    {
        // Pure cn-token query with the Rule-0 escape hatch on. Pre-fix this
        // returned nothing: dropping legality + CI in phase 1 widened the
        // candidate pool but the 200-oracle limit still happened before
        // the cn: filter narrowed anything, so most surviving oracles had
        // no cn=269 printing.
        $deck = $this->makeDeck(CardFormat::Commander);
        $results = DeckCardSearchService::searchPrintingsForDeck(
            $deck,
            'cn:269',
            true,
        );

        $this->assertNotEmpty($results);
        foreach ($results as $card) {
            $this->assertSame('269', $card['printing']['cn']);
        }
    }

    #[Test]
    public function printings_respect_color_identity(): void
    {
        // Even with a set filter, a mono-white Commander deck must not return
        // a red card like Lightning Bolt.
        $deck = $this->makeDeck(CardFormat::Commander, 'W');
        $results = DeckCardSearchService::searchPrintingsForDeck(
            $deck,
            'lightning bolt set:lea'
        );

        $this->assertEmpty($results);
    }

    #[Test]
    public function printings_respect_legality_by_default(): void
    {
        // Black Lotus exists in LEA but is banned in Commander.
        $deck = $this->makeDeck(CardFormat::Commander);
        $results = DeckCardSearchService::searchPrintingsForDeck(
            $deck,
            'black lotus set:lea'
        );

        $this->assertEmpty($results);
    }

    #[Test]
    public function printings_include_non_legal_flag_returns_banned_cards(): void
    {
        // Same query as the previous test, but with the escape hatch engaged:
        // Black Lotus should now come through in a Commander deck.
        $deck = $this->makeDeck(CardFormat::Commander);
        $results = DeckCardSearchService::searchPrintingsForDeck(
            $deck,
            'black lotus set:lea',
            true,
        );

        $this->assertNotEmpty($results);
        $names = array_column($results, 'name');
        $this->assertContains('Black Lotus', $names);
    }

    #[Test]
    public function printings_include_non_legal_also_drops_color_identity(): void
    {
        // `include_non_legal` is the full Rule-0 escape hatch: both legality
        // and color-identity filters are dropped. A mono-white Commander
        // deck with the flag on should surface Lightning Bolt.
        $deck = $this->makeDeck(CardFormat::Commander, 'W');
        $results = DeckCardSearchService::searchPrintingsForDeck(
            $deck,
            'lightning bolt',
            true,
        );

        $names = array_column($results, 'name');
        $this->assertContains('Lightning Bolt', $names);
    }

    // ── Foreign-language search ────────────────────────────────────────────

    #[Test]
    public function oracle_path_finds_card_by_german_printed_name(): void
    {
        // "Blitzschlag" is the German printed name of Lightning Bolt. The
        // service should resolve it to the English oracle via the
        // oracle_card_translations join.
        $results = DeckCardSearchService::searchOracleForDeck(
            $this->makeDeck(CardFormat::Commander, 'WUBRG'),
            'Blitzschlag'
        );

        $this->assertNotEmpty($results);
        $names = array_column($results, 'name');
        $this->assertContains('Lightning Bolt', $names);
    }

    #[Test]
    public function oracle_path_finds_card_by_french_printed_name(): void
    {
        // "Foudre" is the French printed name of Lightning Bolt.
        $results = DeckCardSearchService::searchOracleForDeck(
            $this->makeDeck(CardFormat::Commander, 'WUBRG'),
            'Foudre'
        );

        $this->assertNotEmpty($results);
        $names = array_column($results, 'name');
        $this->assertContains('Lightning Bolt', $names);
    }

    #[Test]
    public function oracle_path_finds_card_by_japanese_printed_name(): void
    {
        // "稲妻" is the Japanese printed name of Lightning Bolt. The
        // CardNameNormalizer folds CJK to Latin script via ICU
        // transliteration so the searchable_name index still applies.
        $results = DeckCardSearchService::searchOracleForDeck(
            $this->makeDeck(CardFormat::Commander, 'WUBRG'),
            '稲妻'
        );

        $this->assertNotEmpty($results);
        $names = array_column($results, 'name');
        $this->assertContains('Lightning Bolt', $names);
    }

    #[Test]
    public function oracle_path_english_regression_unaffected_by_translation_or(): void
    {
        // Sanity: the English query path keeps the previous behavior.
        // Lightning Bolt must still be the top result (or close to it) when
        // the user types its English name — the translation OR adds matches,
        // not noise.
        $results = DeckCardSearchService::searchOracleForDeck(
            $this->makeDeck(CardFormat::Commander, 'WUBRG'),
            'lightning bolt'
        );

        $this->assertNotEmpty($results);
        $this->assertSame('Lightning Bolt', $results[0]['name']);
    }

    #[Test]
    public function printings_path_finds_card_by_german_printed_name(): void
    {
        // The printings entry point should also resolve foreign-language
        // names through the phase-1 oracle prefilter.
        $results = DeckCardSearchService::searchPrintingsForDeck(
            $this->makeDeck(CardFormat::Commander, 'WUBRG'),
            'Blitzschlag'
        );

        $this->assertNotEmpty($results);
        $names = array_column($results, 'name');
        $this->assertContains('Lightning Bolt', $names);
    }

    #[Test]
    public function printings_result_shape_matches_contract(): void
    {
        $results = DeckCardSearchService::searchPrintingsForDeck(
            $this->makeDeck(CardFormat::Vintage),
            'sol ring set:lea'
        );

        $this->assertNotEmpty($results);
        $card = $results[0];

        $this->assertArrayHasKey('oracle_id', $card);
        $this->assertArrayHasKey('name', $card);
        $this->assertArrayHasKey('cmc', $card);
        $this->assertArrayHasKey('color_identity', $card);
        $this->assertArrayHasKey('printing', $card);

        $printing = $card['printing'];
        $this->assertArrayHasKey('id', $printing);
        $this->assertArrayHasKey('card_image_0', $printing);
        $this->assertArrayHasKey('card_image_1', $printing);
        $this->assertArrayHasKey('cn', $printing);
        $this->assertArrayHasKey('finishes', $printing);
        $this->assertArrayHasKey('set', $printing);
        $this->assertSame('lea', $printing['set']['code']);
    }

    // ── Rulebreaker exemptions ─────────────────────────────────────────────

    /**
     * A mono-blue Tolabow deck, optionally with a colour nominated.
     *
     * The command zone is hydrated in memory rather than persisted — these
     * tests never write — but it has to be present, because that is what
     * {@see RulebreakerRegistry::forDeck()} reads to find the rule.
     */
    private function tolabowDeck(?string $chosen = null): Deck
    {
        $deck = $this->makeDeck(CardFormat::Commander, 'U');
        $deck->rulebreaker_color = $chosen;

        $row = new DeckCard;
        $row->zone = DeckZone::Command;
        $row->setRelation('oracleCard', OracleCard::query()->where('name', 'Tolabow, Loch Rascal')->firstOrFail());
        $deck->setRelation('commanders', new Collection([$row]));

        return $deck;
    }

    /**
     * @return array<int, string>
     */
    private function oracleNames(Deck $deck, string $query): array
    {
        return array_column(DeckCardSearchService::searchOracleForDeck($deck, $query, 60), 'name');
    }

    /**
     * The card is in the dataset from Mystery Booster Commander Edition, and
     * the whole feature keys off its presence. Fail loudly rather than let the
     * tests below pass vacuously if a future sync drops it.
     */
    #[Test]
    public function tolabow_is_present_in_the_dataset(): void
    {
        $this->assertNotNull(OracleCard::query()->where('name', 'Tolabow, Loch Rascal')->first());
    }

    #[Test]
    public function search_offers_an_off_colour_instant_once_a_colour_is_nominated(): void
    {
        $this->assertNotContains('Lightning Bolt', $this->oracleNames($this->tolabowDeck(), 'Lightning Bolt'));
        $this->assertContains('Lightning Bolt', $this->oracleNames($this->tolabowDeck('R'), 'Lightning Bolt'));
    }

    /**
     * The widening reaches instants and sorceries only — a red creature stays
     * out, exactly as the validator would flag it.
     */
    #[Test]
    public function search_still_withholds_an_off_colour_creature(): void
    {
        $this->assertNotContains('Goblin Guide', $this->oracleNames($this->tolabowDeck('R'), 'Goblin Guide'));
    }

    /**
     * The case the layout narrowing exists for. Bonecrusher Giant is
     * "Creature — Giant // Instant — Adventure" — a CREATURE card whose second
     * face is an instant. Matching the joined type line would offer it, and
     * would offer 169 other Adventure creatures with it.
     */
    #[Test]
    public function search_does_not_offer_an_adventure_creature_as_an_instant(): void
    {
        $this->assertNotContains('Bonecrusher Giant', $this->oracleNames($this->tolabowDeck('R'), 'Bonecrusher'));
    }

    /**
     * A split card genuinely is both halves, so it must still be offered —
     * the narrowing has to spare `layout = split`, not blanket-truncate.
     */
    #[Test]
    public function search_offers_a_split_card_whose_half_is_an_instant(): void
    {
        $this->assertContains('Fire // Ice', $this->oracleNames($this->tolabowDeck('R'), 'Fire // Ice'));
    }

    #[Test]
    public function search_offers_off_colour_basic_lands_without_any_nomination(): void
    {
        $names = $this->oracleNames($this->tolabowDeck(), 'Mountain');

        $this->assertContains('Mountain', $names);
    }

    /**
     * The basic-land clause is about basics specifically — a red nonbasic land
     * gets nothing, and Grizzlegom is the Rulebreaker that grants any land.
     */
    #[Test]
    public function search_still_withholds_an_off_colour_nonbasic_land(): void
    {
        $this->assertNotContains('Shivan Reef', $this->oracleNames($this->tolabowDeck('R'), 'Shivan Reef'));
    }

    /**
     * An ordinary commander is unaffected: no exemption branch is emitted and
     * the filter behaves exactly as it did before Rulebreakers existed.
     */
    #[Test]
    public function a_deck_without_a_rulebreaker_is_unchanged(): void
    {
        $plain = $this->makeDeck(CardFormat::Commander, 'U');

        $this->assertNotContains('Lightning Bolt', $this->oracleNames($plain, 'Lightning Bolt'));
        $this->assertContains('Counterspell', $this->oracleNames($plain, 'Counterspell'));
    }
}
