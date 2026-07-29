<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\ContainerVisibility;
use App\Enums\DeckCardRole;
use App\Enums\DeckState;
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
 * Feature coverage for the hero-image (`default_card_id`) rule on
 * PATCH /decks/{deck}.
 *
 * The banner may only point at a printing the deck actually carries.
 * Since the `commanders` table and the `decks.companion_*` columns were
 * consolidated into `deck_cards`, a single `deck_cards` lookup covers
 * every role — mainboard, command zone and companion alike.
 *
 * The rejection test is a regression guard: the rule used to fall through
 * to `DB::table('commanders')` whenever the submitted printing was absent
 * from `deck_cards`, which threw `SQLSTATE[42S02] 1146` (table gone) and
 * surfaced as a 500 instead of a 422. `||` short-circuits, so only the
 * invalid path ever reached it — exactly the path a validation test hits.
 *
 * The Local PHPUnit suite uses SQLite. The defensive `mysql` skip keeps
 * the test out of a misconfigured `composer test:mysql` invocation
 * (which would wipe live data via `RefreshDatabase`).
 */
class DeckUpdateHeroImageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hard-skip on real MariaDB connections. See
     * {@see DeckBulkClaimControllerTest::setUp} for the rationale.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data. Run via the default `composer test` (SQLite).');
        }
    }

    private function makeOracleCard(string $name = 'Test Card'): OracleCard
    {
        return OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => Str::lower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => 'R',
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);
    }

    private function makeDefaultCard(OracleCard $oracle): DefaultCard
    {
        $set = Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'set_type' => 'expansion',
            'scryfall_uri' => 'https://example.com/set',
            'path' => 'tst',
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

    private function makeDeck(User $user, CardFormat $format = CardFormat::Legacy): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'Test Deck',
            'format' => $format->value,
            'state' => DeckState::Built->value,
        ]);
    }

    private function makeDeckCard(
        Deck $deck,
        OracleCard $oracle,
        DefaultCard $default,
        DeckZone $zone = DeckZone::Main,
        ?DeckCardRole $role = null
    ): DeckCard {
        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $default->id,
            'zone' => $zone->value,
            'role' => $role?->value,
            'quantity' => 1,
        ]);
    }

    /**
     * Minimum valid payload for the update endpoint. `bracket` is omitted
     * deliberately — it is `prohibited` on formats that don't use the
     * game-changer list.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'deck_name' => 'Renamed Deck',
            'deck_description' => null,
            'deck_visibility' => ContainerVisibility::Private->value,
        ], $overrides);
    }

    #[Test]
    public function it_accepts_a_hero_image_that_is_a_mainboard_printing_of_the_deck(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $this->makeDeckCard($deck, $oracle, $default);

        $response = $this->actingAs($user)
            ->patch("/decks/{$deck->id}", $this->payload(['default_card_id' => $default->id]));

        $response->assertRedirect(route('decks.show', $deck));
        $response->assertSessionHas('type', 'success');
        $this->assertSame($default->id, $deck->fresh()->default_card_id);
    }

    #[Test]
    public function it_accepts_a_hero_image_from_the_command_zone(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user, CardFormat::Commander);
        $commander = $this->makeOracleCard('Test Commander');
        $commanderPrinting = $this->makeDefaultCard($commander);
        $this->makeDeckCard($deck, $commander, $commanderPrinting, DeckZone::Command, DeckCardRole::Commander);

        // commander_id matches the existing command zone, so the diff is a
        // no-op and setCommandZone() is not re-run.
        $response = $this->actingAs($user)->patch("/decks/{$deck->id}", $this->payload([
            'commander_id' => $commander->id,
            'default_card_id' => $commanderPrinting->id,
        ]));

        $response->assertRedirect(route('decks.show', $deck));
        $response->assertSessionHas('type', 'success');
        $this->assertSame($commanderPrinting->id, $deck->fresh()->default_card_id);
    }

    /**
     * Regression: this used to raise a QueryException against the dropped
     * `commanders` table instead of failing validation.
     */
    #[Test]
    public function it_rejects_a_hero_image_that_the_deck_does_not_carry(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $inDeck = $this->makeOracleCard('In Deck');
        $this->makeDeckCard($deck, $inDeck, $this->makeDefaultCard($inDeck));

        // A real printing, but attached to nothing in this deck.
        $stranger = $this->makeDefaultCard($this->makeOracleCard('Not In Deck'));

        $response = $this->actingAs($user)
            ->patch("/decks/{$deck->id}", $this->payload(['default_card_id' => $stranger->id]));

        $response->assertSessionHasErrors('default_card_id');
        $this->assertNull($deck->fresh()->default_card_id);
    }

    #[Test]
    public function it_rejects_a_hero_image_belonging_to_another_users_deck(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $deck = $this->makeDeck($user);
        $otherDeck = $this->makeDeck($other);

        $oracle = $this->makeOracleCard('Foreign Card');
        $foreignPrinting = $this->makeDefaultCard($oracle);
        $this->makeDeckCard($otherDeck, $oracle, $foreignPrinting);

        $response = $this->actingAs($user)
            ->patch("/decks/{$deck->id}", $this->payload(['default_card_id' => $foreignPrinting->id]));

        $response->assertSessionHasErrors('default_card_id');
        $this->assertNull($deck->fresh()->default_card_id);
    }

    #[Test]
    public function it_allows_clearing_the_hero_image(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck($user);
        $oracle = $this->makeOracleCard();
        $default = $this->makeDefaultCard($oracle);
        $this->makeDeckCard($deck, $oracle, $default);
        $deck->update(['default_card_id' => $default->id]);

        $response = $this->actingAs($user)
            ->patch("/decks/{$deck->id}", $this->payload(['default_card_id' => null]));

        $response->assertRedirect(route('decks.show', $deck));
        $this->assertNull($deck->fresh()->default_card_id);
    }
}
