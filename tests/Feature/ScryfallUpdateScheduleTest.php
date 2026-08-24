<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
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
 * cache, so this runs on either testsuite.
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
