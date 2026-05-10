<?php

namespace App\Console\Commands\Notifications;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\ScryfallUpdateFailedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class TestFailureAlert extends Command
{
    /**
     * @var string
     */
    protected $signature = 'notifications:test-failure-alert
        {--channel=both : Which channel(s) to send via — mail, discord, or both}
        {--command=scryfall:oracle : The fake failing command name to embed in the alert}';

    /**
     * @var string
     */
    protected $description = 'Dispatch a fake ScryfallUpdateFailedNotification to verify mail + Discord wiring in isolation.';

    public function handle(): int
    {
        $channelOption = (string) $this->option('channel');
        $commandName = (string) $this->option('command');

        if (! in_array($channelOption, ['both', 'mail', 'discord'], true)) {
            $this->error("--channel must be one of: both, mail, discord (got '{$channelOption}').");

            return self::INVALID;
        }

        $contact = (string) config('app.contact');
        $webhookUrl = (string) config('services.discord.webhook_url');

        if (in_array($channelOption, ['both', 'mail'], true) && empty($contact)) {
            $this->error('config(app.contact) is empty — set APP_CONTACT in .env.');

            return self::FAILURE;
        }

        if (in_array($channelOption, ['both', 'discord'], true) && empty($webhookUrl)) {
            $this->error('config(services.discord.webhook_url) is empty — set DISCORD_WEBHOOK_URL in .env.');

            return self::FAILURE;
        }

        $exception = new RuntimeException(
            "Synthetic test failure dispatched by 'notifications:test-failure-alert' — no real failure occurred."
        );

        $notification = new ScryfallUpdateFailedNotification($commandName, $exception);

        // Build an on-demand notification routing each requested channel
        // explicitly. The DiscordChannel reads its URL from config and
        // ignores its route value, but we still register one for symmetry.
        $route = Notification::route('mail', $contact)
            ->route(DiscordChannel::class, 'webhook');

        // Override via() to honor the --channel option without touching the
        // notification class itself: we wrap with a transient subclass.
        $filtered = new class($commandName, $exception, $channelOption) extends ScryfallUpdateFailedNotification
        {
            public function __construct(string $commandName, \Throwable $exception, private string $channelOption)
            {
                parent::__construct($commandName, $exception);
            }

            public function via(mixed $notifiable): array
            {
                return match ($this->channelOption) {
                    'mail' => ['mail'],
                    'discord' => [DiscordChannel::class],
                    default => ['mail', DiscordChannel::class],
                };
            }
        };

        $route->notify($filtered);

        $this->info('Test failure alert dispatched.');
        $this->line("  channel(s): {$channelOption}");
        if (in_array($channelOption, ['both', 'mail'], true)) {
            $this->line("  mail to:    {$contact}");
        }
        if (in_array($channelOption, ['both', 'discord'], true)) {
            $this->line('  discord:    POST to configured webhook');
        }
        $this->line("  command:    {$commandName}");
        $this->newLine();
        $this->line('Check your inbox and the Discord channel. If nothing arrives, check the Laravel log for DiscordChannel/mail errors.');

        return self::SUCCESS;
    }
}
