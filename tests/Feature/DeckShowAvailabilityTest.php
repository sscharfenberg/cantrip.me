<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\ContainerVisibility;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Http\Controllers\Decks\DecksController;
use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use App\Models\Set;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for the state-driven per-card badge routing in
 * {@see DecksController::show}.
 *
 * Which of the three per-card payloads ships is decided by the deck's
 * *state* first and its collection mode second: a planned deck always
 * reports availability (whatever its mode), a finished or archived deck
 * reports its tracking-mode status. The two are mutually exclusive, and
 * both are owner-only and gated on the collection master switch.
 */
class DeckShowAvailabilityTest extends TestCase
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

    private function makeSet(): Set
    {
        return Set::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Set '.Str::random(6),
            'code' => Str::lower(Str::random(3)),
            'released_at' => '2026-01-01',
            'card_count' => 1,
            'set_type' => 'expansion',
            'scryfall_uri' => 'https://example.com/set',
            'path' => 'tst',
        ]);
    }

    private function makeCard(string $name): DefaultCard
    {
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => strtolower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => 'R',
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
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
            'set_id' => $this->makeSet()->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    /**
     * A deck holding one card the user owns one free copy of, in the
     * given state and collection mode.
     *
     * @return array{0: Deck, 1: DeckCard}
     */
    private function makeDeckWithOwnedCard(User $user, DeckState $state, string $mode): array
    {
        $printing = $this->makeCard('Lightning Bolt');
        CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $printing->id,
            'amount' => 1,
            'finish' => 1,
            'language' => 'en',
        ]);

        $deck = Deck::create([
            'user_id' => $user->id,
            'name' => 'Test Deck',
            'format' => CardFormat::Legacy->value,
            'state' => $state->value,
            'collection_mode' => $mode,
        ]);

        $deckCard = DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $printing->oracle_id,
            'default_card_id' => $printing->id,
            'zone' => DeckZone::Main->value,
            'quantity' => 1,
        ]);

        return [$deck, $deckCard];
    }

    #[Test]
    public function a_planned_deck_ships_availability_instead_of_the_mode_c_status(): void
    {
        // The crux of the feature: mode C would normally render the
        // five-way explicit badge, but a planned deck is being assembled,
        // so it reports availability regardless of tracking mode.
        $user = User::factory()->create();
        [$deck] = $this->makeDeckWithOwnedCard($user, DeckState::Planned, 'C');

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.0.collection_availability.state', 'available')
                ->where('cards.0.collection_status', null)
                ->where('cards.0.collection_implicit_status', null)
            );
    }

    #[Test]
    public function a_planned_deck_in_mode_a_still_ships_availability(): void
    {
        // Mode A is the silent mode and the default for a new deck —
        // which is exactly the deck someone plans in.
        $user = User::factory()->create();
        [$deck] = $this->makeDeckWithOwnedCard($user, DeckState::Planned, 'A');

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.0.collection_availability.state', 'available')
            );
    }

    #[Test]
    public function a_finished_deck_ships_the_mode_status_and_no_availability(): void
    {
        $user = User::factory()->create();
        [$deck] = $this->makeDeckWithOwnedCard($user, DeckState::Built, 'C');

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.0.collection_status', 'available')
                ->where('cards.0.collection_availability', null)
            );
    }

    #[Test]
    public function the_master_switch_silences_availability_on_a_planned_deck(): void
    {
        $user = User::factory()->create(['collection_integration_enabled' => false]);
        [$deck] = $this->makeDeckWithOwnedCard($user, DeckState::Planned, 'C');

        $this->actingAs($user)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.0.collection_availability', null)
                ->where('cards.0.collection_status', null)
            );
    }

    #[Test]
    public function a_visitor_never_sees_the_owners_availability(): void
    {
        // Collection state is personal — a public planned deck shows a
        // visitor the card list and nothing about who owns what.
        $owner = User::factory()->create();
        $visitor = User::factory()->create();
        [$deck] = $this->makeDeckWithOwnedCard($owner, DeckState::Planned, 'C');
        $deck->update(['visibility' => ContainerVisibility::Public->value]);

        $this->actingAs($visitor)
            ->get(route('decks.show', $deck))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cards.0.collection_availability', null)
            );
    }
}
