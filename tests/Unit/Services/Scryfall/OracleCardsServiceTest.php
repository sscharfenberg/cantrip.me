<?php

namespace Tests\Unit\Services\Scryfall;

use App\Services\Scryfall\OracleCardsService;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for the denormalised `oracle_cards.type_line` value built by
 * {@see OracleCardsService::combinedTypeLine()}.
 *
 * The column exists so rules that exempt cards by TYPE — the Rulebreaker
 * commanders — can be expressed as a single predicate in the deck-card search
 * filter instead of an EXISTS against `oracle_card_faces`. What matters is
 * therefore not only that a value is produced, but that every layout produces
 * one shape a `LIKE '%Instant%'` can match, which is what these pin down.
 *
 * No database: the method is a pure mapping from bulk JSON to one string. The
 * surrounding import cannot be exercised on SQLite at all, because
 * `preRunCleanup()` issues `SET FOREIGN_KEY_CHECKS` and only MariaDB
 * understands it — a pre-existing gap shared by four other Scryfall services,
 * not something this column introduced.
 */
class OracleCardsServiceTest extends TestCase
{
    #[Test]
    public function it_uses_the_root_type_line_for_a_single_faced_card(): void
    {
        $this->assertSame('Instant', OracleCardsService::combinedTypeLine([
            'type_line' => 'Instant',
        ]));
    }

    /**
     * Scryfall already joins both halves at the root of a multi-faced card, so
     * the root value is taken verbatim rather than rebuilt from the faces.
     */
    #[Test]
    public function it_prefers_the_root_type_line_over_the_faces(): void
    {
        $this->assertSame('Instant // Sorcery', OracleCardsService::combinedTypeLine([
            'type_line' => 'Instant // Sorcery',
            'card_faces' => [
                ['name' => 'Fire', 'type_line' => 'Instant'],
                ['name' => 'Ice', 'type_line' => 'Sorcery'],
            ],
        ]));
    }

    /**
     * Reversible cards carry no root type_line, which is why the fallback
     * exists. Using the same " // " separator keeps one shape for callers to
     * match regardless of layout.
     */
    #[Test]
    public function it_joins_the_faces_when_the_root_type_line_is_absent(): void
    {
        $this->assertSame('Enchantment // Enchantment', OracleCardsService::combinedTypeLine([
            'layout' => 'reversible_card',
            'card_faces' => [
                ['name' => 'Propaganda', 'type_line' => 'Enchantment'],
                ['name' => 'Propaganda', 'type_line' => 'Enchantment'],
            ],
        ]));
    }

    #[Test]
    public function it_skips_faces_that_carry_no_type_line_of_their_own(): void
    {
        $this->assertSame('Creature — Human', OracleCardsService::combinedTypeLine([
            'card_faces' => [
                ['name' => 'Front', 'type_line' => 'Creature — Human'],
                ['name' => 'Back'],
            ],
        ]));
    }

    #[Test]
    public function it_returns_an_empty_string_when_no_type_line_exists_anywhere(): void
    {
        $this->assertSame('', OracleCardsService::combinedTypeLine([
            'name' => 'Nameless Oddity',
        ]));
        $this->assertSame('', OracleCardsService::combinedTypeLine([
            'card_faces' => [['name' => 'Front'], ['name' => 'Back']],
        ]));
    }

    #[Test]
    public function it_trims_surrounding_whitespace(): void
    {
        $this->assertSame('Instant', OracleCardsService::combinedTypeLine([
            'type_line' => "  Instant\n",
        ]));
    }

    /**
     * The truncation guard exists so an over-long value cannot fail the insert:
     * {@see OracleCardsService::insertCard()} catches and logs, so an overflow
     * would silently drop the entire card from the dataset. Widest real value
     * is 91 of 160 characters, so this is not expected to fire.
     */
    #[Test]
    public function it_truncates_to_the_column_width(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $line = OracleCardsService::combinedTypeLine([
            'name' => 'Absurd Card',
            'type_line' => str_repeat('Creature — Human Wizard ', 20),
        ]);

        $this->assertSame(160, mb_strlen($line));
    }

    /**
     * Truncation is logged rather than silent: the consumer is a type-matching
     * predicate, so a dropped tail would mis-classify that card indefinitely
     * with nothing in the scryfall log to point at.
     */
    #[Test]
    public function it_warns_when_it_has_to_truncate(): void
    {
        Log::shouldReceive('channel')->with('scryfall')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'Absurd Card')
                && str_contains($message, 'truncated'));

        OracleCardsService::combinedTypeLine([
            'name' => 'Absurd Card',
            'type_line' => str_repeat('x', 200),
        ]);
    }

    /**
     * The common path must stay quiet — a warning on every one of ~38k cards
     * would be worse than no warning at all.
     */
    #[Test]
    public function it_does_not_warn_for_a_type_line_within_the_column_width(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->never();

        $this->assertSame('Instant', OracleCardsService::combinedTypeLine([
            'name' => 'Lightning Bolt',
            'type_line' => 'Instant',
        ]));
    }

    /**
     * Multibyte safety: type lines contain an em dash, and cutting mid-sequence
     * with a byte-wise substr would store invalid UTF-8.
     */
    #[Test]
    public function it_truncates_on_character_boundaries_not_bytes(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $line = OracleCardsService::combinedTypeLine([
            'type_line' => str_repeat('—', 200),
        ]);

        $this->assertSame(160, mb_strlen($line));
        $this->assertSame($line, mb_convert_encoding($line, 'UTF-8', 'UTF-8'));
    }

    /**
     * The reason the column exists: Tolabow, Loch Rascal exempts instant and
     * sorcery cards from its deck's colour identity, and the search filter has
     * to express that against this one column.
     */
    #[Test]
    public function produced_values_match_the_like_predicate_the_search_filter_will_use(): void
    {
        $isInstantOrSorcery = static fn (array $card): bool => (bool) preg_match(
            '/Instant|Sorcery/u',
            OracleCardsService::combinedTypeLine($card)
        );

        $this->assertTrue($isInstantOrSorcery(['type_line' => 'Sorcery']));
        $this->assertTrue($isInstantOrSorcery(['type_line' => 'Instant']));
        $this->assertTrue($isInstantOrSorcery(['type_line' => 'Instant // Creature — Human']));
        $this->assertTrue($isInstantOrSorcery([
            'card_faces' => [['type_line' => 'Land'], ['type_line' => 'Sorcery']],
        ]));
        $this->assertFalse($isInstantOrSorcery(['type_line' => 'Creature — Bear']));
        $this->assertFalse($isInstantOrSorcery(['type_line' => 'Legendary Land']));
    }
}
