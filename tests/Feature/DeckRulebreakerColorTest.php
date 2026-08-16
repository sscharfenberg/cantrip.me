<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckCardRole;
use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The endpoint behind the Rulebreaker colour picker.
 *
 * Only Tolabow, Loch Rascal asks for a colour today: "the color identity of
 * instant and sorcery cards in your deck can include one color of your choice
 * not in your commander's color identity". The interesting rules are that the
 * clause is enforced on the CHOICE rather than on the cards, that clearing is
 * legal, and that a deck whose commander grants nothing cannot store one.
 *
 * Skipped on the staging suite — uses RefreshDatabase.
 */
class DeckRulebreakerColorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Uses RefreshDatabase; never run against MariaDB.');
        }
    }

    #[Test]
    public function the_owner_can_nominate_a_colour(): void
    {
        [$user, $deck] = $this->tolabowDeck();

        $this->actingAs($user)
            ->patch(route('decks.rulebreaker-color.set', $deck), ['color' => 'R'])
            ->assertRedirect(route('decks.show', $deck));

        $this->assertSame('R', $deck->fresh()->rulebreaker_color);
    }

    /**
     * Clearing withdraws the widening. A pilot who nominated red and then
     * rebuilt the deck needs a way back, so null is a legal value rather than
     * an omission.
     */
    #[Test]
    public function the_owner_can_clear_the_nomination(): void
    {
        [$user, $deck] = $this->tolabowDeck();
        $deck->update(['rulebreaker_color' => 'R']);

        $this->actingAs($user)
            ->patch(route('decks.rulebreaker-color.set', $deck), ['color' => null])
            ->assertRedirect(route('decks.show', $deck));

        $this->assertNull($deck->fresh()->rulebreaker_color);
    }

    /**
     * "one color of your choice NOT IN your commander's color identity" — a
     * constraint on the choice, so it is refused here rather than tolerated
     * and silently ignored by the rules engine.
     */
    #[Test]
    public function it_rejects_a_colour_already_in_the_commanders_identity(): void
    {
        [$user, $deck] = $this->tolabowDeck();

        $this->actingAs($user)
            ->patch(route('decks.rulebreaker-color.set', $deck), ['color' => 'U'])
            ->assertSessionHasErrors('color');

        $this->assertNull($deck->fresh()->rulebreaker_color);
    }

    #[Test]
    public function it_rejects_a_value_that_is_not_a_colour(): void
    {
        [$user, $deck] = $this->tolabowDeck();

        $this->actingAs($user)
            ->patch(route('decks.rulebreaker-color.set', $deck), ['color' => 'X'])
            ->assertSessionHasErrors('color');
    }

    #[Test]
    public function a_deck_whose_commander_grants_no_choice_cannot_store_one(): void
    {
        [$user, $deck] = $this->tolabowDeck(commanderName: 'Talrand, Sky Summoner');

        $this->actingAs($user)
            ->patch(route('decks.rulebreaker-color.set', $deck), ['color' => 'R'])
            ->assertSessionHasErrors('color');

        $this->assertNull($deck->fresh()->rulebreaker_color);
    }

    #[Test]
    public function a_stranger_cannot_nominate_a_colour(): void
    {
        [, $deck] = $this->tolabowDeck();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->patch(route('decks.rulebreaker-color.set', $deck), ['color' => 'R'])
            ->assertForbidden();

        $this->assertNull($deck->fresh()->rulebreaker_color);
    }

    #[Test]
    public function a_guest_cannot_nominate_a_colour(): void
    {
        [, $deck] = $this->tolabowDeck();

        $this->patch(route('decks.rulebreaker-color.set', $deck), ['color' => 'R'])
            ->assertRedirect(route('login'));

        $this->assertNull($deck->fresh()->rulebreaker_color);
    }

    /**
     * Regression: swapping the commander away from a Rulebreaker leaves the
     * stored colour behind and hides the badge, so if the guard also refused
     * a clear the column would be frozen with no route to remove it — and
     * swapping the Rulebreaker back in would silently restore a colour the
     * pilot never re-chose.
     */
    #[Test]
    public function a_stale_nomination_can_still_be_cleared_after_the_commander_changes(): void
    {
        [$user, $deck] = $this->tolabowDeck(commanderName: 'Talrand, Sky Summoner');
        $deck->update(['rulebreaker_color' => 'R']);

        $this->actingAs($user)
            ->patch(route('decks.rulebreaker-color.set', $deck), ['color' => null])
            ->assertRedirect(route('decks.show', $deck));

        $this->assertNull($deck->fresh()->rulebreaker_color);
    }

    /**
     * @return array{0: User, 1: Deck}
     */
    private function tolabowDeck(string $commanderName = 'Tolabow, Loch Rascal'): array
    {
        $user = User::factory()->create();

        $commander = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $commanderName,
            'searchable_name' => strtolower($commanderName),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 4,
            'color_identity' => 'U',
            'type_line' => 'Legendary Creature — Otter',
            'scryfall_uri' => 'https://example.com/'.Str::slug($commanderName),
        ]);

        $deck = Deck::create([
            'user_id' => $user->id,
            'name' => 'Test Deck',
            'format' => CardFormat::Commander->value,
            'colors' => 'U',
        ]);

        DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $commander->id,
            'default_card_id' => $this->makeDefaultCard($commander)->id,
            'zone' => DeckZone::Command->value,
            'role' => DeckCardRole::Commander->value,
            'quantity' => 1,
        ]);

        return [$user, $deck];
    }

    /** `deck_cards.default_card_id` is NOT NULL, so the row needs a printing. */
    private function makeDefaultCard(OracleCard $oracle): DefaultCard
    {
        $set = Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'scryfall_uri' => 'https://example.com/set',
            'path' => '/sets/test',
        ]);

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => $oracle->name,
            'searchable_name' => $oracle->searchable_name,
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => 1,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }
}
