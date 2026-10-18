<?php

namespace Tests\Unit\Services\Scryfall;

use App\Services\Scryfall\ScryfallService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The retry policy of {@see ScryfallService::api()}, the client every
 * Scryfall JSON API request goes through.
 *
 * The 2026-10-18 nightly run failed because `/bulk-data` took longer than
 * the HTTP client's default 30 s on a slow night for Scryfall, and nothing
 * tried again. These tests pin the four things that keep that from
 * recurring without masking a real outage: a longer timeout, retries for
 * transient failures only, the waits between them, and still failing once
 * the attempts run out.
 *
 * No database, no network: `Http::fake()` answers and `Sleep::fake()`
 * records the waits instead of taking them.
 */
class ScryfallApiClientTest extends TestCase
{
    private const URL = 'https://api.scryfall.com/sets';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    private function client(): PendingRequest
    {
        return (new class extends ScryfallService
        {
            public function client(): PendingRequest
            {
                return $this->api();
            }
        })->client();
    }

    #[Test]
    public function it_waits_longer_than_the_http_clients_default_for_a_response(): void
    {
        $this->assertSame(60, $this->client()->getOptions()['timeout']);
    }

    #[Test]
    public function it_retries_a_timed_out_request(): void
    {
        Http::fake([self::URL => Http::sequence()->pushFailedConnection()->push(['data' => []])]);

        $response = $this->client()->get(self::URL);

        $this->assertTrue($response->successful());
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(10_000)->milliseconds()]);
    }

    #[Test]
    public function it_retries_a_server_error_and_rate_limiting(): void
    {
        Http::fake([self::URL => Http::sequence()->push('', 503)->push('', 429)->push(['data' => []])]);

        $this->assertTrue($this->client()->get(self::URL)->successful());
        Http::assertSentCount(3);
    }

    #[Test]
    public function it_does_not_retry_a_client_error(): void
    {
        Http::fake([self::URL => Http::response(['object' => 'error'], 404)]);

        $response = $this->client()->get(self::URL);

        $this->assertSame(404, $response->status());
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function it_hands_back_a_server_error_that_outlasts_the_retries(): void
    {
        Http::fake([self::URL => Http::response('', 502)]);

        $response = $this->client()->get(self::URL);

        // Returned, not thrown, so each caller's own `failed()` check names
        // the endpoint in the error it raises.
        $this->assertSame(502, $response->status());
        Http::assertSentCount(4);
    }

    #[Test]
    public function it_still_fails_after_four_attempts_against_a_dead_connection(): void
    {
        Http::fake([self::URL => Http::failedConnection()]);

        try {
            $this->client()->get(self::URL);
            $this->fail('A connection that never comes back must still fail the step.');
        } catch (ConnectionException) {
            Http::assertSentCount(4);
            Sleep::assertSequence([
                Sleep::for(10_000)->milliseconds(),
                Sleep::for(30_000)->milliseconds(),
                Sleep::for(90_000)->milliseconds(),
            ]);
        }
    }
}
