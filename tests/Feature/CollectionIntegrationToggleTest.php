<?php

namespace Tests\Feature;

use App\Http\Controllers\User\CollectionIntegrationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for the master switch persisted by
 * {@see CollectionIntegrationController}.
 */
class CollectionIntegrationToggleTest extends TestCase
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

    #[Test]
    public function endpoint_persists_disabled_state(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->collection_integration_enabled);

        $response = $this->actingAs($user)->post('/collection-integration', [
            'collection_integration_enabled' => '0',
        ]);

        $response->assertRedirect();
        $this->assertFalse((bool) $user->fresh()->collection_integration_enabled);
    }

    #[Test]
    public function endpoint_persists_enabled_state(): void
    {
        $user = User::factory()->create(['collection_integration_enabled' => false]);

        $response = $this->actingAs($user)->post('/collection-integration', [
            'collection_integration_enabled' => '1',
        ]);

        $response->assertRedirect();
        $this->assertTrue((bool) $user->fresh()->collection_integration_enabled);
    }

    #[Test]
    public function endpoint_requires_authentication(): void
    {
        $response = $this->post('/collection-integration', [
            'collection_integration_enabled' => '1',
        ]);

        $response->assertRedirect('/login');
    }

    #[Test]
    public function endpoint_validates_payload(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->from('/dashboard')->post('/collection-integration', []);

        $response->assertSessionHasErrors('collection_integration_enabled');
    }
}
