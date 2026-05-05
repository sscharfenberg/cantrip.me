<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class PasswordResetLinkNotification extends ResetPassword
{
    /**
     * Build the password reset mail message.
     *
     * Overrides the default Laravel reset notification to use the
     * application's translated email strings and layout.
     *
     * @param  string  $url  The signed password reset URL.
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject(__('email.reset.subject'))
            ->greeting(__('email.greeting'))
            ->line(__('email.reset.line1'))
            ->action(__('email.reset.action'), $url)
            ->line(__('email.reset.line2', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->line(__('email.reset.line3'))
            ->salutation(__('email.regards')."\n".__('email.team'));
    }
}
