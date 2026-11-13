<?php

namespace App\Services\Scryfall;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\ScryfallUpdateFailedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * Sends the "Scryfall update failed" alert — mail plus a Discord embed,
 * see {@see ScryfallUpdateFailedNotification}.
 *
 * Two senders use it. `scryfall:update` alerts on every step that fails,
 * naming the step and its exception. The schedule's `onFailure` hook
 * alerts when a scheduled run exited non-zero without having done so —
 * a fatal error, an out-of-memory kill — which the command itself can
 * never report. A marker in the cache tells the hook whether the command
 * already alerted, so a normal failure arrives once, not twice.
 *
 * {@see warn()} sends the same alert for a problem the run survives — an
 * oracle-tag column kept from the previous run, constraints a crashed run
 * left dropped that could not be restored. It sets no marker: the run
 * may still die afterwards, and that must still be reported.
 */
class ScryfallFailureAlert
{
    /** Cache key set while the current run has sent an alert. */
    public const SENT_MARKER = 'scryfall:update:failure-alerted';

    /**
     * Alert about a failed step, and mark the run as having alerted.
     */
    public function send(string $command, Throwable $exception): void
    {
        $this->markSent();
        $this->deliver(new ScryfallUpdateFailedNotification($command, $exception));
    }

    /**
     * Alert about a problem the run survives and carries on after. Same
     * channels as {@see send()}, but the run does not count as having
     * alerted about its own failure.
     */
    public function warn(string $command, string $message): void
    {
        $this->deliver(new ScryfallUpdateFailedNotification($command, new RuntimeException($message)));
    }

    /**
     * Send the alert on each channel separately.
     *
     * Laravel's notification sender rethrows a channel's exception and
     * skips the channels after it, so with a single send a mail server
     * that is down would also swallow the Discord message — and mail
     * comes first. Each channel's failure is logged here and never
     * rethrown: it must not mask the error being reported.
     */
    private function deliver(ScryfallUpdateFailedNotification $notification): void
    {
        $notifiable = Notification::route('mail', (string) config('app.contact'))
            ->route(DiscordChannel::class, 'webhook');

        foreach ($notification->via($notifiable) as $channel) {
            try {
                Notification::sendNow($notifiable, $notification, [$channel]);
            } catch (Throwable $alertException) {
                Log::channel('scryfall')->error("Failed to send the scryfall failure alert via $channel.", [
                    'exception' => $alertException::class,
                    'message' => $alertException->getMessage(),
                ]);
            }
        }
    }

    /**
     * Forget an earlier run's alert. Called by the schedule before each
     * run starts, so {@see sendUnlessAlreadySent()} only counts alerts
     * from the run it follows.
     */
    public function forgetSent(): void
    {
        try {
            Cache::forget(self::SENT_MARKER);
        } catch (Throwable) {
            // Without a cache the hook alerts once more; nothing to fix here.
        }
    }

    /**
     * Alert about a scheduled run that exited non-zero, unless the run
     * already sent its own, more specific alert.
     */
    public function sendUnlessAlreadySent(): void
    {
        if ($this->wasSent()) {
            return;
        }

        $this->send('scryfall:update', new RuntimeException(
            'the scheduled run exited non-zero without reporting a failure of its own — '
            .'most likely a fatal error or the process was killed (out of memory?). '
            .'See storage/logs/cron-scryfall.log.'
        ));
    }

    private function markSent(): void
    {
        try {
            Cache::put(self::SENT_MARKER, true, now()->addDay());
        } catch (Throwable) {
            // The schedule's hook then sends a second, generic alert.
        }
    }

    private function wasSent(): bool
    {
        try {
            return Cache::get(self::SENT_MARKER) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
