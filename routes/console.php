<?php

use App\Jobs\CleanupTempUploads;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
