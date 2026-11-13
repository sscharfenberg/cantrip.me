<?php

namespace Tests\Feature;

use App\Notifications\ScryfallUpdateFailedNotification;
use App\Services\Scryfall\ScryfallFailureAlert;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Pins the scheduling of the nightly Scryfall sync.
 *
 * This exists because of a real outage: the command used to be an
 * /etc/cron.d line wrapped in `flock -n /tmp/...` with `> /dev/null 2>&1`,
 * and it failed silently for eight consecutive nights (2026-08-16..23)
 * without producing a single log line or notification anywhere. The two
 * properties that keep that from recurring — output is kept, and the
 * overlap lock expires long before it can block a whole day — are easy to
 * drop by accident and invisible when they are, so they are asserted here.
 *
 * No database: registering the schedule touches neither the DB nor the
 * cache, and the failure-hook tests use the array cache store, so this
 * runs on either testsuite.
 */
class ScryfallUpdateScheduleTest extends TestCase
{
    #[Test]
    public function scryfall_update_is_scheduled_nightly_on_production_only(): void
    {
        $event = $this->scryfallEvent();

        $this->assertSame('0 4 * * *', $event->getExpression(), '04:00 app time = 02:00 UTC in summer');
        $this->assertTrue($event->runsInEnvironment('production'));
        $this->assertFalse(
            $event->runsInEnvironment('local'),
            'staging runs APP_ENV=local and shares asset dirs with prod — it must not sync on a schedule'
        );
    }

    #[Test]
    public function a_crashed_run_cannot_block_the_following_night(): void
    {
        $event = $this->scryfallEvent();

        $this->assertTrue($event->withoutOverlapping, 'concurrent runs would corrupt the shadow build');
        $this->assertSame(
            60,
            $event->expiresAt,
            "withoutOverlapping()'s 1440-minute default would let a SIGKILLed run block a full day"
        );
    }

    #[Test]
    public function the_runs_output_is_kept_rather_than_discarded(): void
    {
        $event = $this->scryfallEvent();

        $this->assertNotSame(
            '/dev/null',
            $event->output,
            'discarding output is exactly what hid the 2026-08-16..23 failures'
        );
        $this->assertTrue($event->shouldAppendOutput, 'each night must add to the log, not overwrite it');
    }

    #[Test]
    public function a_run_that_dies_without_alerting_is_alerted_by_the_schedule(): void
    {
        Notification::fake();
        Log::shouldReceive('channel')->with('scryfall')->andReturnSelf();
        Log::shouldReceive('error');
        $event = $this->scryfallEvent();

        $event->callBeforeCallbacks(app());
        $event->exitCode = 255;
        $event->callAfterCallbacks(app());

        Notification::assertSentOnDemand(
            ScryfallUpdateFailedNotification::class,
            fn ($notification): bool => $notification->commandName === 'scryfall:update',
        );
    }

    #[Test]
    public function a_run_that_alerted_itself_is_not_alerted_twice(): void
    {
        Notification::fake();
        Log::shouldReceive('channel')->with('scryfall')->andReturnSelf();
        Log::shouldReceive('error');
        $event = $this->scryfallEvent();

        $event->callBeforeCallbacks(app());
        (new ScryfallFailureAlert)->send('scryfall:rulings', new RuntimeException('boom'));
        $event->exitCode = 1;
        $event->callAfterCallbacks(app());

        Notification::assertSentOnDemandTimes(ScryfallUpdateFailedNotification::class, 2);
        Notification::assertNotSentTo(
            new AnonymousNotifiable,
            ScryfallUpdateFailedNotification::class,
            fn ($notification): bool => $notification->commandName === 'scryfall:update',
        );
    }

    #[Test]
    public function an_earlier_runs_alert_does_not_hide_tonights_death(): void
    {
        Notification::fake();
        Log::shouldReceive('channel')->with('scryfall')->andReturnSelf();
        Log::shouldReceive('error');
        $event = $this->scryfallEvent();
        (new ScryfallFailureAlert)->send('scryfall:rulings', new RuntimeException('a manual run yesterday'));

        $event->callBeforeCallbacks(app());
        $event->exitCode = 255;
        $event->callAfterCallbacks(app());

        Notification::assertSentOnDemand(
            ScryfallUpdateFailedNotification::class,
            fn ($notification): bool => $notification->commandName === 'scryfall:update',
        );
    }

    private function scryfallEvent(): Event
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'scryfall:update'),
        ));

        $this->assertCount(1, $events, 'expected exactly one scheduled scryfall:update event');

        return $events[0];
    }
}
