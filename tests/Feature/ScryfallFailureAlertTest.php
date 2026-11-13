<?php

namespace Tests\Feature;

use App\Notifications\ScryfallUpdateFailedNotification;
use App\Services\Scryfall\ScryfallFailureAlert;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Coverage for {@see ScryfallFailureAlert}: that a broken mail server
 * cannot silence the Discord alert, and that a scheduled run that failed
 * the ordinary way is reported once, not twice.
 *
 * No database: the cache is the array store under test.
 */
class ScryfallFailureAlertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Log::shouldReceive('channel')->with('scryfall')->andReturnSelf();
        Log::shouldReceive('error');
    }

    #[Test]
    public function discord_is_alerted_even_when_mail_fails(): void
    {
        config(['services.discord.webhook_url' => 'https://discord.test/webhook']);
        Http::fake(['discord.test/*' => Http::response(null, 204)]);
        app(ChannelManager::class)->extend('mail', fn () => new class
        {
            public function send(): void
            {
                throw new RuntimeException('mail server down');
            }
        });

        (new ScryfallFailureAlert)->send('scryfall:rulings', new RuntimeException('boom'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://discord.test/webhook'
            && str_contains($request['embeds'][0]['title'], 'scryfall:rulings'));
    }

    #[Test]
    public function the_alert_goes_out_by_mail_and_to_discord(): void
    {
        Notification::fake();

        (new ScryfallFailureAlert)->send('scryfall:rulings', new RuntimeException('boom'));

        Notification::assertSentOnDemand(
            ScryfallUpdateFailedNotification::class,
            fn ($notification, array $channels): bool => $channels === ['mail'],
        );
        Notification::assertSentOnDemand(
            ScryfallUpdateFailedNotification::class,
            fn ($notification, array $channels): bool => $channels !== ['mail'] && $notification->commandName === 'scryfall:rulings',
        );
    }

    /**
     * Staging and production share a server and staging runs APP_ENV=local,
     * so neither the hostname nor the environment says where an alert
     * came from — the site does.
     */
    #[Test]
    public function the_alert_names_the_site_it_comes_from(): void
    {
        config(['app.url' => 'https://staging.cantrip.me']);
        $notification = new ScryfallUpdateFailedNotification('scryfall:rulings', new RuntimeException('boom'));
        $notifiable = new AnonymousNotifiable;

        $mail = $notification->toMail($notifiable);
        $discord = $notification->toDiscord($notifiable)['embeds'][0];

        $this->assertStringStartsWith('[staging.cantrip.me] ', $mail->subject);
        $this->assertStringContainsString('staging.cantrip.me', $mail->introLines[0]);
        $this->assertStringContainsString('staging.cantrip.me', $discord['title']);
        $this->assertContains(['name' => 'Site', 'value' => 'staging.cantrip.me', 'inline' => true], $discord['fields']);
    }

    #[Test]
    public function a_run_that_already_alerted_is_not_reported_again(): void
    {
        Notification::fake();
        $alert = new ScryfallFailureAlert;

        $alert->send('scryfall:rulings', new RuntimeException('boom'));
        $alert->sendUnlessAlreadySent();

        Notification::assertSentOnDemandTimes(ScryfallUpdateFailedNotification::class, 2);
    }

    #[Test]
    public function a_warning_does_not_count_as_the_run_having_alerted(): void
    {
        Notification::fake();
        $alert = new ScryfallFailureAlert;

        $alert->warn('scryfall:oracle-tags', 'kept the previous run\'s values for mld.');
        $alert->sendUnlessAlreadySent();

        Notification::assertSentOnDemandTimes(ScryfallUpdateFailedNotification::class, 4);
        Notification::assertSentOnDemand(
            ScryfallUpdateFailedNotification::class,
            fn ($notification): bool => $notification->commandName === 'scryfall:update',
        );
    }

    #[Test]
    public function a_run_that_died_without_alerting_is_reported(): void
    {
        Notification::fake();
        $alert = new ScryfallFailureAlert;
        $alert->send('scryfall:rulings', new RuntimeException('last night'));

        $alert->forgetSent();
        $alert->sendUnlessAlreadySent();

        Notification::assertSentOnDemandTimes(ScryfallUpdateFailedNotification::class, 4);
        Notification::assertSentOnDemand(
            ScryfallUpdateFailedNotification::class,
            fn ($notification): bool => $notification->commandName === 'scryfall:update'
                && str_contains($notification->exception->getMessage(), 'without reporting a failure of its own'),
        );
    }
}
