<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckState;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The deck page tells the frontend whether the deck's format has a
 * command zone — the "Command zone" button in the deck navigation shows
 * only when it does.
 */
class DeckShowCommandZoneFlagTest extends TestCase
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

    /**
     * @return array<string, array{0: CardFormat, 1: bool}>
     */
    public static function formats(): array
    {
        return [
            'commander' => [CardFormat::Commander, true],
            'oathbreaker' => [CardFormat::Oathbreaker, true],
            'brawl' => [CardFormat::Brawl, true],
            'gladiator, singleton without one' => [CardFormat::Gladiator, false],
            'legacy' => [CardFormat::Legacy, false],
        ];
    }

    #[Test]
    #[DataProvider('formats')]
    public function the_deck_says_whether_its_format_has_a_command_zone(CardFormat $format, bool $expected): void
    {
        $user = User::factory()->create();
        $deck = Deck::create([
            'user_id' => $user->id,
            'name' => 'Deck',
            'format' => $format->value,
            'state' => DeckState::Planned->value,
        ]);

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('deck.requires_commander', $expected));
    }
}
