<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

class ScryfallUpdateFailedNotification extends Notification
{
    public function __construct(
        public string $commandName,
        public Throwable $exception,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail', DiscordChannel::class];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $host = gethostname() ?: 'unknown host';
        $time = now()->toDateTimeString();

        return (new MailMessage)
            ->subject(__('email.scryfall_failure.subject', ['command' => $this->commandName]))
            ->greeting(__('email.greeting'))
            ->line(__('email.scryfall_failure.intro', ['host' => $host]))
            ->line(__('email.scryfall_failure.command_label').' `'.$this->commandName.'`')
            ->line(__('email.scryfall_failure.exception_label').' `'.$this->exception::class.'`')
            ->line(__('email.scryfall_failure.message_label').' '.$this->exception->getMessage())
            ->line(__('email.scryfall_failure.location_label').' '.$this->exception->getFile().':'.$this->exception->getLine())
            ->line(__('email.scryfall_failure.time_label').' '.$time)
            ->line(__('email.scryfall_failure.closing'))
            ->salutation(__('email.regards')."\n".__('email.team'));
    }

    /**
     * Build the Discord webhook payload (a single red-stripe embed).
     *
     * @return array<string, mixed>
     */
    public function toDiscord(mixed $notifiable): array
    {
        $host = gethostname() ?: 'unknown host';
        $message = $this->exception->getMessage();
        // Discord embed field values cap at 1024 chars; trim defensively.
        if (mb_strlen($message) > 900) {
            $message = mb_substr($message, 0, 900).'…';
        }

        return [
            'username' => 'cantrip.me alerts',
            'embeds' => [[
                'title' => '🚨 Scryfall update failed: '.$this->commandName,
                'description' => '`'.$this->exception::class.'`: '.$message,
                'color' => 0xE74C3C,
                'fields' => [
                    [
                        'name' => 'Host',
                        'value' => $host,
                        'inline' => true,
                    ],
                    [
                        'name' => 'Environment',
                        'value' => app()->environment(),
                        'inline' => true,
                    ],
                    [
                        'name' => 'Location',
                        'value' => '`'.basename($this->exception->getFile()).':'.$this->exception->getLine().'`',
                        'inline' => false,
                    ],
                ],
                'timestamp' => now()->toIso8601String(),
            ]],
        ];
    }
}
