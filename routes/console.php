<?php

use App\Jobs\CleanupTempUploads;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new CleanupTempUploads)->daily()->evenInMaintenanceMode();
// DB backup runs 15 minutes after the temp-upload cleanup so the two
// don't contend for I/O at midnight. Production-only — staging shares
// most assets with prod and we don't want a daily flood of staging
// dumps cluttering the disk. The command itself has no env gating, so
// running `php artisan db:backup` manually on staging still works for
// ad-hoc testing. evenInMaintenanceMode() so a prod deploy that
// catches midnight still gets its daily snapshot.
Schedule::command('db:backup')
    ->dailyAt('00:15')
    ->environments(['production'])
    ->evenInMaintenanceMode();

// Explicit nightly sweep of expired session rows. Laravel's lottery
// (config/session.php) already triggers gc() on ~2% of requests, so
// this is belt-and-suspenders rather than load-bearing — but it makes
// the cleanup deterministic instead of traffic-dependent.
Schedule::call(function () {
    $handler = app('session')->driver()->getHandler();
    $handler->gc(config('session.lifetime') * 60);
})->dailyAt('03:00')->name('session-gc');

// Nightly Scryfall sync. This replaces a line in /etc/cron.d/cantrip that
// wrapped the command in `flock -n /tmp/...` and threw its output away with
// `> /dev/null 2>&1`. That combination hid eight consecutive failures
// (2026-08-16..23): the runs bailed out of flock before Laravel booted, so
// there was nothing in any log and no notification — the breakage only
// surfaced when a later run got far enough to report itself.
//
// Both halves of that are fixed here. withoutOverlapping() takes a cache
// lock the app owns instead of a file in world-writable /tmp, where a
// leftover from a different unix user silently blocks every later run and
// systemd's tmpfiles reaper deletes it on its own schedule. And output is
// appended rather than discarded, so a failure that happens before the
// command can log anything still leaves a trace.
//
// The lock TTL matters: withoutOverlapping() defaults to 24 hours, so a
// hard-killed run (SIGKILL never gets to release the lock) would block
// every following night for a full day — the same shape of failure as the
// flock leftover. A full run takes ~7 minutes, so 60 tolerates a
// pathologically slow one and still self-heals before the next attempt.
//
// Production-only, matching the cron line it replaces. 04:00 Europe/Berlin
// is 02:00 UTC in summer, which is when that line fired; the scheduler
// works in the app timezone, so this shifts by an hour across DST. Doesn't
// matter for a data sync, and it stays clear of db:backup and session-gc.
Schedule::command('scryfall:update')
    ->dailyAt('04:00')
    ->environments(['production'])
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/cron-scryfall.log'))
    ->onFailure(function (): void {
        Log::channel('scryfall')->error(
            'scheduled scryfall:update exited non-zero — see storage/logs/cron-scryfall.log.'
        );
    });
