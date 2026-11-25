<?php

namespace Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Pins the healthchecks.io pings of the nightly db:backup.
 *
 * They are the dead-man's switch for the backup and for the scheduler
 * itself: nothing else notices a night on which no task ran at all. Each
 * test rebuilds the schedule from routes/console.php, because the ping URL
 * is read when the schedule is defined, which the app's boot has already
 * done before a test can set it.
 *
 * No database: the schedule touches neither the DB nor the cache, so this
 * runs on either testsuite.
 */
class DbBackupScheduleTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $requests = [];

    #[Test]
    public function a_successful_backup_pings_its_check(): void
    {
        $event = $this->dbBackupEvent('https://hc-ping.com/abc');

        $event->exitCode = 0;
        $event->callAfterCallbacks($this->app);

        $this->assertSame(['https://hc-ping.com/abc'], $this->pingedUrls());
    }

    #[Test]
    public function a_failed_backup_marks_its_check_failed(): void
    {
        $event = $this->dbBackupEvent('https://hc-ping.com/abc');

        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);

        $this->assertSame(['https://hc-ping.com/abc/fail'], $this->pingedUrls());
    }

    #[Test]
    public function without_a_check_url_nothing_is_pinged(): void
    {
        $event = $this->dbBackupEvent(null);

        $event->exitCode = 0;
        $event->callAfterCallbacks($this->app);
        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);

        $this->assertSame([], $this->pingedUrls(), 'staging and local have no check to ping');
    }

    #[Test]
    public function the_backup_still_runs_nightly_on_production_only(): void
    {
        $event = $this->dbBackupEvent('https://hc-ping.com/abc');

        $this->assertSame('15 0 * * *', $event->getExpression());
        $this->assertTrue($event->runsInEnvironment('production'));
        $this->assertFalse($event->runsInEnvironment('local'));
        $this->assertTrue($event->runsInMaintenanceMode(), 'a deploy at midnight must not cost the dump');
    }

    /**
     * The db:backup event of a schedule defined afresh with the given ping URL,
     * with every outgoing ping recorded instead of sent.
     */
    private function dbBackupEvent(?string $pingUrl): Event
    {
        config(['services.healthchecks.db_backup' => $pingUrl]);
        $schedule = new Schedule;
        ScheduleFacade::swap($schedule);
        require base_path('routes/console.php');

        $stack = HandlerStack::create(new MockHandler(array_fill(0, 4, new Response(200))));
        $stack->push(Middleware::history($this->requests));
        $this->app->instance(ClientInterface::class, new Client(['handler' => $stack]));

        $events = array_values(array_filter(
            $schedule->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'db:backup'),
        ));
        $this->assertCount(1, $events, 'expected exactly one scheduled db:backup event');

        return $events[0];
    }

    /**
     * The URLs pinged so far, in order.
     *
     * @return list<string>
     */
    private function pingedUrls(): array
    {
        return array_map(fn (array $entry): string => (string) $entry['request']->getUri(), $this->requests);
    }
}
