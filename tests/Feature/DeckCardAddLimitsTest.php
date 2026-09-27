<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature coverage for the format rules on the two ways a copy enters a deck:
 * PATCH /api/decks/{deck}/cards/{deckCard}/quantity ("+") and
 * POST /api/decks/{deck}/cards (Add Cards, Quick Add). Both go through one
 * check, so the same deck must get the same answer from either.
 *
 * The deck-size cap counts mainboard + command zone only — the same count
 * DeckValidator and the deck header use. It used to sum every zone, so a
 * Commander deck with a maybeboard refused a basic land it had room for.
 * Adding a card used to skip the check entirely, so a full deck took a
 * 101st card through Add Cards while "+" refused it.
 * A refusal ships the AddCopyFailure as `reason` so the UI can explain it.
 *
 * The Local PHPUnit suite uses SQLite. The defensive `mysql` skip keeps
 * the test out of a misconfigured `composer test:mysql` invocation
 * (which would wipe live data via `RefreshDatabase`).
 */
class DeckCardAddLimitsTest extends TestCase
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

    private function makeOracleCard(string $name): OracleCard
    {
        return OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => Str::lower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 0,
            'color_identity' => '',
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

    private function makeDeckCard(
        Deck $deck,
        string $name,
        int $quantity,
        DeckZone $zone = DeckZone::Main,
        ?DeckCardRole $role = null,
    ): DeckCard {
        $oracle = $this->makeOracleCard($name);

        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $oracle->id,
            'default_card_id' => $this->makeDefaultCard($oracle)->id,
            'zone' => $zone->value,
            'role' => $role?->value,
            'quantity' => $quantity,
        ]);
    }

    /**
     * A Commander deck whose commander + mainboard total `$deckSize`:
     * the commander, a Swamp row with one copy, and filler for the rest.
     *
     * @return array{0: User, 1: Deck, 2: DeckCard}
     */
    private function commanderDeck(int $deckSize, string $commander = 'Test Commander'): array
    {
        $user = User::factory()->create();
        $deck = Deck::create([
            'user_id' => $user->id,
            'name' => 'Test Deck',
            'format' => CardFormat::Commander->value,
            'state' => DeckState::Built->value,
        ]);
        $this->makeDeckCard($deck, $commander, 1, DeckZone::Command, DeckCardRole::Commander);
        $this->makeDeckCard($deck, 'Filler', $deckSize - 2);
        $swamp = $this->makeDeckCard($deck, 'Swamp', 1);

        return [$user, $deck, $swamp];
    }

    private function increment(User $user, Deck $deck, DeckCard $deckCard): TestResponse
    {
        return $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/cards/{$deckCard->id}/quantity", ['delta' => 1]);
    }

    private function add(User $user, Deck $deck, DeckCard $likeRow, DeckZone $zone = DeckZone::Main): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/decks/{$deck->id}/cards", [
            'default_card_id' => $likeRow->default_card_id,
            'zone' => $zone->value,
        ]);
    }

    #[Test]
    public function it_adds_a_basic_land_while_the_deck_has_room(): void
    {
        [$user, $deck, $swamp] = $this->commanderDeck(99);

        $this->increment($user, $deck, $swamp)->assertOk()->assertJson(['quantity' => 2]);
        $this->assertSame(2, $swamp->fresh()->quantity);
    }

    #[Test]
    public function it_refuses_a_basic_land_on_a_full_deck_and_says_why(): void
    {
        [$user, $deck, $swamp] = $this->commanderDeck(100);

        $this->increment($user, $deck, $swamp)
            ->assertUnprocessable()
            ->assertExactJson(['reason' => 'exceeds_deck_size']);
        $this->assertSame(1, $swamp->fresh()->quantity);
    }

    /**
     * Regression: the maybeboard used to count toward the deck size, so this
     * 99-card deck with five maybes read as 104 and refused the Swamp.
     */
    #[Test]
    public function the_maybeboard_does_not_count_toward_the_deck_size(): void
    {
        [$user, $deck, $swamp] = $this->commanderDeck(99);
        $this->makeDeckCard($deck, 'Maybe Card', 5, DeckZone::Maybe);

        $this->increment($user, $deck, $swamp)->assertOk()->assertJson(['quantity' => 2]);
    }

    #[Test]
    public function a_maybeboard_row_is_not_capped_by_a_full_deck(): void
    {
        [$user, $deck] = $this->commanderDeck(100);
        $maybeSwamp = $this->makeDeckCard($deck, 'Swamp', 1, DeckZone::Maybe);

        $this->increment($user, $deck, $maybeSwamp)->assertOk()->assertJson(['quantity' => 2]);
    }

    /**
     * Whtz, the Bibliophile: "a deck with this commander has no maximum
     * deck size". The Rulebreaker lifts the ceiling, not the copy limit.
     */
    #[Test]
    public function a_whtz_deck_takes_a_basic_land_past_one_hundred(): void
    {
        [$user, $deck, $swamp] = $this->commanderDeck(100, 'Whtz, the Bibliophile');

        $this->increment($user, $deck, $swamp)->assertOk()->assertJson(['quantity' => 2]);
    }

    #[Test]
    public function a_whtz_deck_still_enforces_singleton(): void
    {
        [$user, $deck] = $this->commanderDeck(100, 'Whtz, the Bibliophile');
        $nonBasic = $this->makeDeckCard($deck, 'Sol Ring', 1);

        $this->increment($user, $deck, $nonBasic)
            ->assertUnprocessable()
            ->assertExactJson(['reason' => 'violates_singleton']);
    }

    #[Test]
    public function it_names_the_singleton_rule_when_that_is_what_refuses(): void
    {
        [$user, $deck] = $this->commanderDeck(50);
        $nonBasic = $this->makeDeckCard($deck, 'Sol Ring', 1);

        $this->increment($user, $deck, $nonBasic)
            ->assertUnprocessable()
            ->assertExactJson(['reason' => 'violates_singleton']);
    }

    #[Test]
    public function adding_a_card_to_a_deck_with_room_creates_the_row(): void
    {
        [$user, $deck, $swamp] = $this->commanderDeck(99);

        $this->add($user, $deck, $swamp)->assertCreated();
        $this->assertSame(2, (int) $deck->deckCards()->where('oracle_card_id', $swamp->oracle_card_id)->sum('quantity'));
    }

    /**
     * Regression: store() had no format check, so Add Cards took a 101st
     * card that "+" refused.
     */
    #[Test]
    public function adding_a_card_to_a_full_deck_is_refused_like_the_plus_button(): void
    {
        [$user, $deck, $swamp] = $this->commanderDeck(100);

        $this->add($user, $deck, $swamp)
            ->assertUnprocessable()
            ->assertExactJson(['reason' => 'exceeds_deck_size']);
        $this->assertSame(1, $deck->deckCards()->where('oracle_card_id', $swamp->oracle_card_id)->count());
    }

    #[Test]
    public function adding_a_second_copy_of_a_non_basic_violates_singleton(): void
    {
        [$user, $deck] = $this->commanderDeck(50);
        $solRing = $this->makeDeckCard($deck, 'Sol Ring', 1);

        $this->add($user, $deck, $solRing)
            ->assertUnprocessable()
            ->assertExactJson(['reason' => 'violates_singleton']);
    }

    #[Test]
    public function adding_to_the_maybeboard_is_not_capped_by_a_full_deck(): void
    {
        [$user, $deck, $swamp] = $this->commanderDeck(100);

        $this->add($user, $deck, $swamp, DeckZone::Maybe)->assertCreated();
    }

    #[Test]
    public function a_whtz_deck_takes_an_added_card_past_one_hundred(): void
    {
        [$user, $deck, $swamp] = $this->commanderDeck(100, 'Whtz, the Bibliophile');

        $this->add($user, $deck, $swamp)->assertCreated();
    }
}
