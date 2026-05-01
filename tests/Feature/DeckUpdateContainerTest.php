<?php

namespace Tests\Feature;

use App\Enums\CardFormat;
use App\Enums\DeckState;
use App\Models\Container;
use App\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2.3 — feature coverage for the deck-edit container picker
 * (`PATCH /decks/{deck}` carrying `container_id`).
 *
 *  - Setting `container_id` to a user-owned container persists.
 *  - Updating to a different container persists.
 *  - Submitting an empty `container_id` clears the deck's container.
 *  - A foreign container id (owned by another user) is rejected (validation
 *    failure) and the deck stays unchanged.
 *  - Owner gate still applies (covered by the existing
 *    `abort_unless` in `DecksController::update`).
 */
class DeckUpdateContainerTest extends TestCase
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

    private function makeDeck(User $user, ?string $containerId = null): Deck
    {
        return Deck::create([
            'user_id' => $user->id,
            'name' => 'Container Picker Test Deck',
            'format' => CardFormat::Legacy->value,
            'state' => DeckState::Built->value,
            'container_id' => $containerId,
            'visibility' => 'private',
        ]);
    }

    private function makeContainer(User $user, string $type = 'deckbox'): Container
    {
        return Container::create([
            'user_id' => $user->id,
            'name' => 'Test Container',
            'type' => $type,
            'sort_order' => 1,
        ]);
    }

    /**
     * Build the body that the deck-edit form would submit. Required
     * fields stay constant across tests so each test only varies what
     * matters to it (the container_id value).
     *
     * @return array<string, string>
     */
    private function baseEditPayload(Deck $deck, ?string $containerId): array
    {
        return [
            'deck_name' => $deck->name,
            'deck_description' => $deck->description ?? '',
            'deck_visibility' => $deck->visibility->value,
            'container_id' => $containerId ?? '',
        ];
    }

    #[Test]
    public function update_persists_container_id_for_owner(): void
    {
        $user = User::factory()->create();
        $container = $this->makeContainer($user);
        $deck = $this->makeDeck($user);

        $response = $this->actingAs($user)->patch(
            "/decks/{$deck->id}",
            $this->baseEditPayload($deck, $container->id),
        );

        $response->assertRedirect("/decks/{$deck->id}");
        $this->assertSame($container->id, $deck->fresh()->container_id);
    }

    #[Test]
    public function update_changes_container_id_to_a_different_owned_container(): void
    {
        $user = User::factory()->create();
        $first = $this->makeContainer($user);
        $second = $this->makeContainer($user, type: 'binder');
        $deck = $this->makeDeck($user, $first->id);

        $response = $this->actingAs($user)->patch(
            "/decks/{$deck->id}",
            $this->baseEditPayload($deck, $second->id),
        );

        $response->assertRedirect("/decks/{$deck->id}");
        $this->assertSame($second->id, $deck->fresh()->container_id);
    }

    #[Test]
    public function update_clears_container_id_when_empty_string_submitted(): void
    {
        $user = User::factory()->create();
        $container = $this->makeContainer($user);
        $deck = $this->makeDeck($user, $container->id);

        $response = $this->actingAs($user)->patch(
            "/decks/{$deck->id}",
            $this->baseEditPayload($deck, ''),
        );

        $response->assertRedirect("/decks/{$deck->id}");
        $this->assertNull($deck->fresh()->container_id);
    }

    #[Test]
    public function update_rejects_a_foreign_container_id(): void
    {
        // Smuggling a container id owned by another user via the URL bar
        // / curl must fail validation. The deck stays unchanged.
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreignContainer = $this->makeContainer($other);
        $deck = $this->makeDeck($owner);

        $response = $this->actingAs($owner)->patch(
            "/decks/{$deck->id}",
            $this->baseEditPayload($deck, $foreignContainer->id),
        );

        $response->assertSessionHasErrors('container_id');
        $this->assertNull($deck->fresh()->container_id);
    }
}
