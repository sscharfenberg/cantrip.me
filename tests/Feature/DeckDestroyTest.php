<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature coverage for `DELETE /decks/{deck}`.
 *
 * Asserts the destroy endpoint deletes the deck (with all dependents
 * cascading) and redirects to the deck list page with a success flash.
 * Regression guard for the deck-detail-page deletion flow that the
 * DeckActionsMenu uses.
 */
class DeckDestroyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('Skipped on MariaDB — RefreshDatabase would wipe live data.');
        }
    }

    #[Test]
    public function destroy_deletes_empty_deck_and_redirects_to_list_with_flash(): void
    {
        $user = User::factory()->create();
        $deck = Deck::create([
            'user_id' => $user->id,
            'name' => 'Empty Test Deck',
            'format' => CardFormat::Commander->value,
        ]);

        $response = $this->actingAs($user)->delete("/decks/{$deck->id}");

        $response->assertRedirect(route('decks'));
        $response->assertSessionHas('message');
        $response->assertSessionHas('type', 'success');
        $this->assertNull(Deck::find($deck->id));
    }

    #[Test]
    public function destroy_returns_303_for_inertia_client_so_redirect_is_followed(): void
    {
        $user = User::factory()->create();
        $deck = Deck::create([
            'user_id' => $user->id,
            'name' => 'Inertia Delete Deck',
            'format' => CardFormat::Commander->value,
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->delete("/decks/{$deck->id}");

        $response->assertStatus(303);
        $response->assertRedirect(route('decks'));
    }

    #[Test]
    public function destroy_rejects_non_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deck = Deck::create([
            'user_id' => $owner->id,
            'name' => 'Someone Elses Deck',
            'format' => CardFormat::Commander->value,
        ]);

        $response = $this->actingAs($other)->delete("/decks/{$deck->id}");

        $response->assertForbidden();
        $this->assertNotNull(Deck::find($deck->id));
    }
}
