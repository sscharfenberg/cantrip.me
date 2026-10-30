<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckCardRole;
use App\Enums\DeckState;
use App\Enums\DeckZone;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
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
 * "Switch printing" in the three card menus — deck card, commander,
 * companion — moves a card's claimed copies to the new printing with it,
 * so the deck and the collection keep agreeing on which cards they are.
 */
class DeckCardSwitchPrintingClaimsTest extends TestCase
{
    use RefreshDatabase;

    private OracleCard $oracle;

    /** Exists in nonfoil and foil. */
    private DefaultCard $alpha;

    /** Exists in nonfoil and foil. */
    private DefaultCard $beta;

    /** Exists in nonfoil only. */
    private DefaultCard $nonfoilOnly;

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

        $this->oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => 'Lightning Bolt',
            'searchable_name' => 'lightning bolt',
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => 'R',
            'scryfall_uri' => 'https://example.com/bolt',
        ]);
        $this->alpha = $this->printing(3);
        $this->beta = $this->printing(3);
        $this->nonfoilOnly = $this->printing(1);
    }

    #[Test]
    public function the_claimed_copies_take_the_new_printing(): void
    {
        [$user, $deck, $deckCard] = $this->deckCard(4);
        $binder = $this->container($user, 'Binder');
        $stack = $this->claim($deckCard, $binder, 3, Finish::Foil);

        $this->actingAs($user)
            ->patchJson($this->printingUrl($deck, $deckCard), ['default_card_id' => $this->beta->id])
            ->assertNoContent();

        $this->assertSame($this->beta->id, $deckCard->fresh()->default_card_id);
        $stack->refresh();
        $this->assertSame($this->beta->id, $stack->default_card_id);
        $this->assertSame(Finish::Foil, $stack->finish);
        $this->assertSame(3, $stack->amount);
        $this->assertSame($binder->id, $stack->container_id);
        $this->assertTrue($deckCard->cardStacks()->whereKey($stack->id)->exists());
    }

    #[Test]
    public function a_finish_the_new_printing_lacks_falls_back_to_its_first(): void
    {
        [$user, $deck, $deckCard] = $this->deckCard(1);
        $stack = $this->claim($deckCard, $this->container($user, 'Binder'), 1, Finish::Foil);

        $this->actingAs($user)
            ->patchJson($this->printingUrl($deck, $deckCard), ['default_card_id' => $this->nonfoilOnly->id])
            ->assertNoContent();

        $stack->refresh();
        $this->assertSame($this->nonfoilOnly->id, $stack->default_card_id);
        $this->assertSame(Finish::Nonfoil, $stack->finish);
    }

    #[Test]
    public function copies_that_become_the_same_cards_fold_into_one_stack_but_never_into_an_unclaimed_one(): void
    {
        [$user, $deck, $deckCard] = $this->deckCard(4);
        $binder = $this->container($user, 'Binder');
        $moving = $this->claim($deckCard, $binder, 2);
        $twin = $this->claim($deckCard, $binder, 1, printing: $this->beta);
        $unclaimed = $this->stack($user, $this->beta, $binder, 5);

        $this->actingAs($user)
            ->patchJson($this->printingUrl($deck, $deckCard), ['default_card_id' => $this->beta->id])
            ->assertNoContent();

        $this->assertNull(CardStack::find($moving->id));
        $this->assertSame(3, $twin->fresh()->amount);
        $this->assertSame([$twin->id], $deckCard->cardStacks()->pluck('card_stacks.id')->all());
        $this->assertSame(5, $unclaimed->fresh()->amount);
    }

    #[Test]
    public function a_stack_that_already_was_another_printing_stays_as_it_is(): void
    {
        [$user, $deck, $deckCard] = $this->deckCard(2);
        $other = $this->claim($deckCard, $this->container($user, 'Binder'), 1, printing: $this->nonfoilOnly);

        $this->actingAs($user)
            ->patchJson($this->printingUrl($deck, $deckCard), ['default_card_id' => $this->beta->id])
            ->assertNoContent();

        $this->assertSame($this->nonfoilOnly->id, $other->fresh()->default_card_id);
    }

    #[Test]
    public function a_stack_another_deck_claims_too_changes_for_that_deck_as_well(): void
    {
        [$user, $deck, $deckCard] = $this->deckCard(2);
        $stack = $this->claim($deckCard, $this->container($user, 'Binder'), 2);
        $otherCard = $this->addCard($this->deck($user, 'Burn'), 2);
        $otherCard->cardStacks()->attach($stack->id);

        $this->actingAs($user)
            ->patchJson($this->printingUrl($deck, $deckCard), ['default_card_id' => $this->beta->id])
            ->assertNoContent();

        $this->assertSame($this->beta->id, $stack->fresh()->default_card_id);
        $this->assertTrue($otherCard->cardStacks()->whereKey($stack->id)->exists());
        $this->assertSame($this->alpha->id, $otherCard->fresh()->default_card_id);
    }

    #[Test]
    public function with_the_master_switch_off_the_collection_is_left_alone(): void
    {
        [$user, $deck, $deckCard] = $this->deckCard(2);
        $stack = $this->claim($deckCard, $this->container($user, 'Binder'), 2);
        $user->update(['collection_integration_enabled' => false]);

        $this->actingAs($user)
            ->patchJson($this->printingUrl($deck, $deckCard), ['default_card_id' => $this->beta->id])
            ->assertNoContent();

        $this->assertSame($this->beta->id, $deckCard->fresh()->default_card_id);
        $this->assertSame($this->alpha->id, $stack->fresh()->default_card_id);
    }

    #[Test]
    public function the_hero_image_follows_the_printing_it_showed(): void
    {
        [$user, $deck, $deckCard] = $this->deckCard(1);
        $deck->update(['default_card_id' => $this->alpha->id]);

        $this->actingAs($user)
            ->patchJson($this->printingUrl($deck, $deckCard), ['default_card_id' => $this->beta->id])
            ->assertNoContent();

        $this->assertSame($this->beta->id, $deck->fresh()->default_card_id);
    }

    #[Test]
    public function a_commanders_claimed_copy_takes_the_new_printing(): void
    {
        [$user, $deck] = $this->deckCard(1);
        $commander = $this->addCard($deck, 1, DeckZone::Command, DeckCardRole::Commander);
        $stack = $this->claim($commander, $this->container($user, 'Binder'), 1);

        $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/commander/{$this->oracle->id}/printing", ['default_card_id' => $this->beta->id])
            ->assertNoContent();

        $this->assertSame($this->beta->id, $commander->fresh()->default_card_id);
        $this->assertSame($this->beta->id, $stack->fresh()->default_card_id);
    }

    #[Test]
    public function a_companions_claimed_copy_takes_the_new_printing(): void
    {
        [$user, $deck] = $this->deckCard(1);
        $companion = $this->addCard($deck, 1, DeckZone::Companion, DeckCardRole::Companion);
        $stack = $this->claim($companion, $this->container($user, 'Binder'), 1);

        $this->actingAs($user)
            ->patchJson("/api/decks/{$deck->id}/companion/printing", ['default_card_id' => $this->beta->id])
            ->assertNoContent();

        $this->assertSame($this->beta->id, $companion->fresh()->default_card_id);
        $this->assertSame($this->beta->id, $stack->fresh()->default_card_id);
    }

    private function printingUrl(Deck $deck, DeckCard $deckCard): string
    {
        return "/api/decks/{$deck->id}/cards/{$deckCard->id}/printing";
    }

    /**
     * A mode-C deck of a new user with the master switch on, holding one
     * card of `$quantity` copies of the Alpha printing, nothing claimed yet.
     *
     * @return array{0: User, 1: Deck, 2: DeckCard}
     */
    private function deckCard(int $quantity): array
    {
        $user = User::factory()->create(['collection_integration_enabled' => true]);
        $deck = $this->deck($user, 'Lightning');

        return [$user, $deck, $this->addCard($deck, $quantity)];
    }

    private function addCard(Deck $deck, int $quantity, DeckZone $zone = DeckZone::Main, ?DeckCardRole $role = null): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $deck->id,
            'oracle_card_id' => $this->oracle->id,
            'default_card_id' => $this->alpha->id,
            'zone' => $zone->value,
            'role' => $role?->value,
            'quantity' => $quantity,
        ]);
    }

    /** A stack of `$printing` (the deck card's own by default) claimed for the deck card. */
    private function claim(DeckCard $deckCard, Container $container, int $amount, Finish $finish = Finish::Nonfoil, ?DefaultCard $printing = null): CardStack
    {
        $stack = $this->stack($deckCard->deck->user, $printing ?? $this->alpha, $container, $amount, $finish);
        $deckCard->cardStacks()->attach($stack->id);

        return $stack;
    }

    private function stack(User $user, DefaultCard $printing, Container $container, int $amount, Finish $finish = Finish::Nonfoil): CardStack
    {
        return CardStack::create([
            'user_id' => $user->id,
            'default_card_id' => $printing->id,
            'container_id' => $container->id,
            'amount' => $amount,
            'finish' => $finish->value,
            'language' => 'en',
        ]);
    }

    /** A printing of the oracle card in a set of its own, existing in the finishes of `$finishes`. */
    private function printing(int $finishes): DefaultCard
    {
        $code = Str::lower(Str::random(3));
        $set = Set::create([
            'id' => (string) Str::uuid(),
            'name' => "Set {$code}",
            'code' => $code,
            'released_at' => '1993-08-05',
            'card_count' => 1,
            'set_type' => 'core',
            'scryfall_uri' => 'https://example.com/set',
            'path' => 'tst',
        ]);

        return DefaultCard::create([
            'id' => (string) Str::uuid(),
            'name' => 'Lightning Bolt',
            'searchable_name' => 'lightning bolt',
            'collector_number' => (string) random_int(1, 400),
            'layout' => 'normal',
            'lang' => 'en',
            'finishes' => $finishes,
            'games' => 1,
            'rarity' => 'common',
            'set_id' => $set->id,
            'oracle_id' => $this->oracle->id,
        ]);
    }

    private function deck(User $user, string $name): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => $name,
            'format' => CardFormat::Commander->value,
            'state' => DeckState::Built->value,
            'collection_mode' => 'C',
        ]);
    }

    private function container(User $user, string $name): Container
    {
        return Container::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => 'binder',
            'sort_order' => 1,
        ]);
    }
}
