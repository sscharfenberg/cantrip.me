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
 * A claim belongs to its copies: moving a copy to another zone, splitting a
 * card over several printings, changing the command zone and replacing or
 * removing the companion keep every claim with the copy it covers — or, when
 * the card leaves the deck, free it, asking first where copies lying in the
 * deck's deckbox go.
 */
class DeckClaimsFollowTheirCopiesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Deck $deck;

    private Container $binder;

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

        $this->user = User::factory()->create(['collection_integration_enabled' => true]);
        $this->deck = Deck::create([
            'user_id' => $this->user->id,
            'name' => 'Goblins',
            'format' => CardFormat::Commander->value,
            'state' => DeckState::Built->value,
            'collection_mode' => 'C',
        ]);
        $this->binder = $this->container('Binder');
    }

    #[Test]
    public function a_copy_moving_zones_takes_its_claim_along_when_every_copy_is_claimed(): void
    {
        [$printing] = $this->card('Goblin Guide');
        $main = $this->row($printing, 2);
        $stack = $this->claim($main, 2);

        $response = $this->actingAs($this->user)
            ->patchJson($this->cardUrl($main, 'move-zone'), ['zone' => 'side'])
            ->assertOk();

        $side = DeckCard::find($response->json('target_id'));
        $this->assertSame([$stack->id], $this->claimed($main));
        $this->assertSame(1, $stack->fresh()->amount);
        $this->assertSame([1], $side->cardStacks()->pluck('card_stacks.amount')->all());
        $this->assertSame($side->cardStacks()->value('card_stacks.id'), $response->json('target_claimed_stacks.0.id'));
        $this->assertSame([$stack->id], array_column($response->json('source_claimed_stacks'), 'id'));
    }

    #[Test]
    public function an_unclaimed_copy_moves_first_and_the_claims_stay(): void
    {
        [$printing] = $this->card('Goblin Guide');
        $main = $this->row($printing, 3);
        $stack = $this->claim($main, 2);

        $response = $this->actingAs($this->user)
            ->patchJson($this->cardUrl($main, 'move-zone'), ['zone' => 'side'])
            ->assertOk();

        $this->assertSame([$stack->id], $this->claimed($main));
        $this->assertSame(2, $stack->fresh()->amount);
        $this->assertSame([], $response->json('target_claimed_stacks'));
    }

    #[Test]
    public function the_last_copy_moving_zones_keeps_its_claim_on_the_new_row(): void
    {
        [$printing] = $this->card('Goblin Guide');
        $main = $this->row($printing, 1);
        $stack = $this->claim($main, 1);

        $response = $this->actingAs($this->user)
            ->patchJson($this->cardUrl($main, 'move-zone'), ['zone' => 'side'])
            ->assertOk();

        $this->assertNull(DeckCard::find($main->id));
        $this->assertSame([$stack->id], $this->claimed(DeckCard::find($response->json('target_id'))));
    }

    #[Test]
    public function rows_the_move_consolidates_pass_their_claims_to_the_survivor(): void
    {
        [$printing] = $this->card('Goblin Guide');
        $main = $this->row($printing, 2);
        $survivor = $this->row($printing, 1, DeckZone::Side);
        $duplicate = $this->row($printing, 1, DeckZone::Side);
        $stack = $this->claim($duplicate, 1);

        $this->actingAs($this->user)
            ->patchJson($this->cardUrl($main, 'move-zone'), ['zone' => 'side'])
            ->assertOk()
            ->assertJsonPath('merged_ids', [$duplicate->id]);

        $this->assertSame(3, $survivor->fresh()->quantity);
        $this->assertSame([$stack->id], $this->claimed($survivor));
    }

    #[Test]
    public function a_split_keeps_each_claim_with_its_printing_and_gives_the_rest_the_new_one(): void
    {
        [$alpha, $beta] = $this->card('Mountain', 2);
        $row = $this->row($alpha, 4);
        $stack = $this->claim($row, 4);

        $this->split($row, [[$alpha, 2], [$beta, 2]]);

        $betaRow = DeckCard::query()->where('default_card_id', $beta->id)->sole();
        $this->assertSame([$stack->id], $this->claimed($row));
        $this->assertSame(2, $stack->fresh()->amount);
        $moved = $betaRow->cardStacks()->sole();
        $this->assertSame(2, $moved->amount);
        $this->assertSame($beta->id, $moved->default_card_id);
        $this->assertSame($this->binder->id, $moved->container_id);
    }

    #[Test]
    public function a_split_that_drops_the_old_printing_reprints_every_claimed_copy(): void
    {
        [$alpha, $beta, $gamma] = $this->card('Mountain', 3);
        $row = $this->row($alpha, 2);
        $this->claim($row, 2);

        $this->split($row, [[$beta, 1], [$gamma, 1]]);

        foreach ([$beta, $gamma] as $printing) {
            $claimed = DeckCard::query()->where('default_card_id', $printing->id)->sole()->cardStacks()->sole();
            $this->assertSame([1, $printing->id], [$claimed->amount, $claimed->default_card_id]);
        }
    }

    #[Test]
    public function a_stack_that_already_was_another_printing_keeps_it_through_a_split(): void
    {
        [$alpha, $beta, $gamma] = $this->card('Mountain', 3);
        $row = $this->row($alpha, 2);
        $other = $this->claim($row, 1, $gamma);
        $own = $this->claim($row, 1);

        $this->split($row, [[$beta, 1], [$alpha, 1]]);

        $this->assertSame([$own->id], $this->claimed(DeckCard::query()->where('default_card_id', $alpha->id)->sole()));
        $this->assertSame([$other->id], $this->claimed(DeckCard::query()->where('default_card_id', $beta->id)->sole()));
        $this->assertSame($gamma->id, $other->fresh()->default_card_id);
    }

    #[Test]
    public function a_split_hands_each_claimed_stack_to_the_row_of_its_own_printing_whatever_the_order(): void
    {
        [$alpha, $beta] = $this->card('Mountain', 2);
        $row = $this->row($alpha, 4);
        $own = $this->claim($row, 2);
        $other = $this->claim($row, 2, $beta);

        $this->split($row, [[$beta, 2], [$alpha, 2]]);

        $this->assertSame([$other->id], $this->claimed(DeckCard::query()->where('default_card_id', $beta->id)->sole()));
        $this->assertSame([$own->id], $this->claimed(DeckCard::query()->where('default_card_id', $alpha->id)->sole()));
        $this->assertSame([$alpha->id, $beta->id], [$own->fresh()->default_card_id, $other->fresh()->default_card_id]);
    }

    #[Test]
    public function with_the_master_switch_off_a_split_moves_claims_but_leaves_the_collection_alone(): void
    {
        [$alpha, $beta] = $this->card('Mountain', 2);
        $row = $this->row($alpha, 2);
        $this->claim($row, 2);
        $this->user->update(['collection_integration_enabled' => false]);

        $this->split($row, [[$alpha, 1], [$beta, 1]]);

        $moved = DeckCard::query()->where('default_card_id', $beta->id)->sole()->cardStacks()->sole();
        $this->assertSame([1, $alpha->id], [$moved->amount, $moved->default_card_id]);
    }

    #[Test]
    public function a_commander_that_stays_keeps_its_row_printing_and_claim(): void
    {
        [$krenkoOld] = $this->card('Krenko, Mob Boss', 1, 'R', '2012-01-01');
        $this->printingOf($krenkoOld->oracle, '2024-01-01');
        [$partner] = $this->card('Tymna the Weaver', 1, 'WB');
        $commander = $this->row($krenkoOld, 1, DeckZone::Command, DeckCardRole::Commander);
        $stack = $this->claim($commander, 1);

        $this->actingAs($this->user)
            ->patchJson($this->commanderUrl(), ['commander_id' => $krenkoOld->oracle_id, 'companion_id' => $partner->oracle_id])
            ->assertNoContent();

        $commander->refresh();
        $this->assertSame([DeckCardRole::Commander, $krenkoOld->id], [$commander->role, $commander->default_card_id]);
        $this->assertSame([$stack->id], $this->claimed($commander));
        $this->assertTrue(DeckCard::query()->where('role', DeckCardRole::Partner->value)->where('oracle_card_id', $partner->oracle_id)->exists());
    }

    #[Test]
    public function a_commander_and_partner_trading_places_keep_their_rows(): void
    {
        [$thrasios] = $this->card('Thrasios, Triton Hero', 1, 'GU');
        [$tymna] = $this->card('Tymna the Weaver', 1, 'WB');
        $commander = $this->row($thrasios, 1, DeckZone::Command, DeckCardRole::Commander);
        $partner = $this->row($tymna, 1, DeckZone::Command, DeckCardRole::Partner);

        $this->actingAs($this->user)
            ->patchJson($this->commanderUrl(), ['commander_id' => $tymna->oracle_id, 'companion_id' => $thrasios->oracle_id])
            ->assertNoContent();

        $this->assertSame(DeckCardRole::Partner, $commander->fresh()->role);
        $this->assertSame(DeckCardRole::Commander, $partner->fresh()->role);
    }

    #[Test]
    public function a_commander_leaving_asks_where_its_copies_in_the_deckbox_go_then_puts_them_there(): void
    {
        [$krenko] = $this->card('Krenko, Mob Boss', 1, 'R');
        [$purphoros] = $this->card('Purphoros, God of the Forge', 1, 'R');
        $deckbox = $this->container('Deckbox');
        $this->deck->update(['container_id' => $deckbox->id]);
        $commander = $this->row($krenko, 1, DeckZone::Command, DeckCardRole::Commander);
        $stack = $this->claim($commander, 1, container: $deckbox);

        $this->actingAs($this->user)
            ->patchJson($this->commanderUrl(), ['commander_id' => $purphoros->oracle_id])
            ->assertStatus(409)
            ->assertExactJson(['reason' => 'freed_copies_need_a_place', 'count' => 1, 'container_id' => $deckbox->id, 'name' => 'Krenko, Mob Boss']);
        $this->assertSame([$stack->id], $this->claimed($commander));

        $this->actingAs($this->user)
            ->patchJson($this->commanderUrl(), ['commander_id' => $purphoros->oracle_id, 'move_to' => $this->binder->id])
            ->assertNoContent();

        $this->assertNull(DeckCard::find($commander->id));
        $this->assertFalse($stack->fresh()->isClaimed());
        $this->assertSame($this->binder->id, $stack->fresh()->container_id);
    }

    #[Test]
    public function a_commander_claimed_from_elsewhere_leaves_without_asking_and_its_copy_stays(): void
    {
        [$krenko] = $this->card('Krenko, Mob Boss', 1, 'R');
        [$purphoros] = $this->card('Purphoros, God of the Forge', 1, 'R');
        $this->deck->update(['container_id' => $this->container('Deckbox')->id]);
        $stack = $this->claim($this->row($krenko, 1, DeckZone::Command, DeckCardRole::Commander), 1);

        $this->actingAs($this->user)
            ->patchJson($this->commanderUrl(), ['commander_id' => $purphoros->oracle_id])
            ->assertNoContent();

        $this->assertFalse($stack->fresh()->isClaimed());
        $this->assertSame($this->binder->id, $stack->fresh()->container_id);
    }

    #[Test]
    public function picking_the_same_companion_again_changes_nothing(): void
    {
        [$lurrus] = $this->card('Lurrus of the Dream-Den', 1, 'WB');
        $this->deck->update(['format' => CardFormat::Legacy->value]);
        $companion = $this->row($lurrus, 1, DeckZone::Companion, DeckCardRole::Companion);
        $stack = $this->claim($companion, 1);

        $this->actingAs($this->user)
            ->patchJson($this->companionUrl(), ['oracle_card_id' => $lurrus->oracle_id])
            ->assertOk();

        $this->assertSame([$stack->id], $this->claimed($companion));
    }

    #[Test]
    public function a_replaced_companion_asks_where_its_copies_in_the_deckbox_go(): void
    {
        [$lurrus] = $this->card('Lurrus of the Dream-Den', 1, 'WB');
        [$jegantha] = $this->card('Jegantha, the Wellspring', 1, 'R');
        $deckbox = $this->container('Deckbox');
        $this->deck->update(['container_id' => $deckbox->id, 'format' => CardFormat::Legacy->value]);
        $companion = $this->row($lurrus, 1, DeckZone::Companion, DeckCardRole::Companion);
        $stack = $this->claim($companion, 1, container: $deckbox);

        $this->actingAs($this->user)
            ->patchJson($this->companionUrl(), ['oracle_card_id' => $jegantha->oracle_id])
            ->assertStatus(409)
            ->assertJsonPath('name', 'Lurrus of the Dream-Den');
        $this->assertSame([$stack->id], $this->claimed($companion));

        $this->actingAs($this->user)
            ->patchJson($this->companionUrl(), ['oracle_card_id' => $jegantha->oracle_id, 'move_to' => 'unsorted'])
            ->assertOk()
            ->assertJsonPath('companion_oracle_card_id', $jegantha->oracle_id);

        $this->assertFalse($stack->fresh()->isClaimed());
        $this->assertNull($stack->fresh()->container_id);
    }

    #[Test]
    public function a_removed_companion_asks_where_its_copies_in_the_deckbox_go(): void
    {
        [$lurrus] = $this->card('Lurrus of the Dream-Den', 1, 'WB');
        $deckbox = $this->container('Deckbox');
        $this->deck->update(['container_id' => $deckbox->id]);
        $companion = $this->row($lurrus, 1, DeckZone::Companion, DeckCardRole::Companion);
        $stack = $this->claim($companion, 1, container: $deckbox);

        $this->actingAs($this->user)->deleteJson($this->companionUrl())->assertStatus(409);
        $this->assertNotNull(DeckCard::find($companion->id));

        $this->actingAs($this->user)
            ->deleteJson($this->companionUrl(), ['move_to' => $this->binder->id])
            ->assertOk();

        $this->assertNull(DeckCard::find($companion->id));
        $this->assertSame($this->binder->id, $stack->fresh()->container_id);
    }

    #[Test]
    public function the_deck_edit_form_asks_before_a_commander_leaves_and_writes_nothing_until_answered(): void
    {
        [$krenko] = $this->card('Krenko, Mob Boss', 1, 'R');
        [$purphoros] = $this->card('Purphoros, God of the Forge', 1, 'R');
        $deckbox = $this->container('Deckbox');
        $this->deck->update(['container_id' => $deckbox->id]);
        $stack = $this->claim($this->row($krenko, 1, DeckZone::Command, DeckCardRole::Commander), 1, container: $deckbox);
        $form = [
            'deck_name' => 'Renamed',
            'deck_visibility' => 'private',
            'commander_id' => $purphoros->oracle_id,
            'container_id' => $deckbox->id,
        ];

        $this->actingAs($this->user)
            ->from("/decks/{$this->deck->id}/edit")
            ->patch("/decks/{$this->deck->id}", $form)
            ->assertSessionHasErrors('move_to');
        $this->assertSame('Goblins', $this->deck->fresh()->name);
        $this->assertTrue($stack->fresh()->isClaimed());

        $this->actingAs($this->user)
            ->patch("/decks/{$this->deck->id}", [...$form, 'move_to' => $this->binder->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $this->deck->fresh()->name);
        $this->assertFalse($stack->fresh()->isClaimed());
        $this->assertSame($this->binder->id, $stack->fresh()->container_id);
    }

    private function cardUrl(DeckCard $row, string $action): string
    {
        return "/api/decks/{$this->deck->id}/cards/{$row->id}/{$action}";
    }

    private function commanderUrl(): string
    {
        return "/api/decks/{$this->deck->id}/commander";
    }

    private function companionUrl(): string
    {
        return "/api/decks/{$this->deck->id}/companion";
    }

    /**
     * @param  list<array{0: DefaultCard, 1: int}>  $entries
     */
    private function split(DeckCard $row, array $entries): void
    {
        $this->actingAs($this->user)
            ->patchJson($this->cardUrl($row, 'split'), [
                'splits' => array_map(fn (array $entry): array => ['default_card_id' => $entry[0]->id, 'quantity' => $entry[1]], $entries),
            ])
            ->assertNoContent();
    }

    /** @return list<string> */
    private function claimed(DeckCard $row): array
    {
        return $row->cardStacks()->orderBy('card_stacks.created_at')->pluck('card_stacks.id')->all();
    }

    /**
     * An oracle card with `$printings` printings, oldest first.
     *
     * @return list<DefaultCard>
     */
    private function card(string $name, int $printings = 1, string $colors = 'R', string $releasedFrom = '2000-01-01'): array
    {
        $oracle = OracleCard::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'searchable_name' => Str::lower($name),
            'collector_number' => '1',
            'layout' => 'normal',
            'lang' => 'en',
            'cmc' => 1,
            'color_identity' => $colors,
            'scryfall_uri' => 'https://example.com/'.Str::slug($name),
        ]);

        $result = [];
        for ($i = 0; $i < $printings; $i++) {
            $result[] = $this->printingOf($oracle, date('Y-m-d', strtotime("{$releasedFrom} +{$i} years")));
        }

        return $result;
    }

    private function printingOf(OracleCard $oracle, string $releasedAt): DefaultCard
    {
        $code = Str::lower(Str::random(4));
        $set = Set::create([
            'id' => (string) Str::uuid(),
            'name' => "Set {$code}",
            'code' => $code,
            'released_at' => $releasedAt,
            'card_count' => 1,
            'set_type' => 'core',
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
            'finishes' => 3,
            'games' => 1,
            'rarity' => 'rare',
            'released_at' => $releasedAt,
            'set_id' => $set->id,
            'oracle_id' => $oracle->id,
        ]);
    }

    private function row(DefaultCard $printing, int $quantity, DeckZone $zone = DeckZone::Main, ?DeckCardRole $role = null): DeckCard
    {
        return DeckCard::create([
            'deck_id' => $this->deck->id,
            'oracle_card_id' => $printing->oracle_id,
            'default_card_id' => $printing->id,
            'zone' => $zone->value,
            'role' => $role?->value,
            'quantity' => $quantity,
        ]);
    }

    /** A nonfoil stack claimed for the row: of its printing unless named, in the binder unless named. */
    private function claim(DeckCard $row, int $amount, ?DefaultCard $printing = null, ?Container $container = null): CardStack
    {
        $stack = CardStack::create([
            'user_id' => $this->user->id,
            'default_card_id' => $printing?->id ?? $row->default_card_id,
            'container_id' => ($container ?? $this->binder)->id,
            'amount' => $amount,
            'finish' => Finish::Nonfoil->value,
            'language' => 'en',
        ]);
        $row->cardStacks()->attach($stack->id);

        return $stack;
    }

    private function container(string $name): Container
    {
        return Container::create([
            'user_id' => $this->user->id,
            'name' => $name,
            'type' => 'deckbox',
            'sort_order' => 1,
        ]);
    }
}
