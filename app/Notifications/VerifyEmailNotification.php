<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    /**
     * Build the email verification mail message.
     *
     * Overrides the default Laravel verification notification to use the
     * application's translated email strings and layout.
     *
     * @param  string  $url  The signed email verification URL.
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email.verification.subject'))
            ->greeting(__('email.greeting'))
            ->line(__('email.verification.line1'))
            ->action(__('email.verification.action'), $url)
            ->line(__('email.verification.line2'))
            ->salutation(__('email.regards')."\n".__('email.team'));
    }
}
