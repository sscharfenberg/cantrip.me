<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordChannel
{
    /**
     * Send the given notification by POSTing its toDiscord() payload to the
     * configured webhook URL.
     *
     * The notification must implement a toDiscord($notifiable) method that
     * returns an array shaped for Discord's webhook API, e.g.:
     *   ['content' => '...', 'embeds' => [[...]]].
     *
     * Failures are logged but never thrown — the alert pipeline must not
     * itself become a source of unhandled exceptions.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        $webhookUrl = config('services.discord.webhook_url');
        if (empty($webhookUrl)) {
            Log::warning('DiscordChannel: services.discord.webhook_url is empty, skipping notification.');

            return;
        }

        if (! method_exists($notification, 'toDiscord')) {
            Log::warning('DiscordChannel: notification '.$notification::class.' is missing toDiscord().');

            return;
        }

        $payload = $notification->toDiscord($notifiable);

        try {
            $response = Http::timeout(10)
                ->asJson()
                ->post($webhookUrl, $payload);

            if ($response->failed()) {
                Log::error('DiscordChannel: webhook POST failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('DiscordChannel: webhook POST threw.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
