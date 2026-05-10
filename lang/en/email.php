<?php

return [

    'greeting' => 'Hello!',
    'regards' => 'Regards,',
    'team' => 'the cantrip.me team.',
    'footer' => 'All rights reserved.',
    'subcopy' => 'If you\'re having trouble clicking the ":actionText" button, copy and paste the URL below into your web browser:',

    'verification' => [
        'subject' => 'Verify Email Address',
        'line1' => 'Please click the button below to verify your email address.',
        'action' => 'Verify Email Address',
        'line2' => 'If you did not create an account, no further action is required.',
    ],

    'reset' => [
        'subject' => 'Reset Password',
        'line1' => 'You are receiving this email because we received a password reset request for your account.',
        'action' => 'Reset Password',
        'line2' => 'This password reset link will expire in :count minutes.',
        'line3' => 'If you did not request a password reset, no further action is required.',
    ],

    'username' => [
        'subject' => 'Your Username',
        'line1' => 'You are receiving this email because we received a username reminder request for your account.',
        'line2' => 'Your username is: :name',
        'line3' => 'If you did not request this, no further action is required.',
    ],

    'scryfall_failure' => [
        'subject' => '[cantrip.me] Scryfall update failed: :command',
        'intro' => 'A scryfall update step failed on :host.',
        'command_label' => 'Failing command:',
        'exception_label' => 'Exception:',
        'message_label' => 'Message:',
        'location_label' => 'Location:',
        'time_label' => 'Time:',
        'closing' => 'See the scryfall log channel for the full stack trace.',
    ],

];
