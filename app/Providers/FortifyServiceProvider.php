<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\EnsureEmailIsVerified;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Prevent Fortify from auto-registering its default route set. Routes
        // are defined manually in web.php so unused endpoints are not exposed.
        Fortify::ignoreRoutes();

        $responseBindings = [
            Contracts\RegisterResponse::class => Responses\RegisterResponse::class,
            Contracts\VerifyEmailResponse::class => Responses\VerifyEmailResponse::class,
            Contracts\ProfileInformationUpdatedResponse::class => Responses\ProfileInformationUpdatedResponse::class,
            Contracts\PasswordUpdateResponse::class => Responses\PasswordUpdateResponse::class,
            Contracts\FailedTwoFactorLoginResponse::class => Responses\FailedTwoFactorLoginResponse::class,
            Contracts\LoginResponse::class => Responses\LoginResponse::class,
            Contracts\TwoFactorLoginResponse::class => Responses\TwoFactorLoginResponse::class,
            Contracts\LogoutResponse::class => Responses\LogoutResponse::class,
            Contracts\TwoFactorConfirmedResponse::class => Responses\TwoFactorConfirmedResponse::class,
            Contracts\TwoFactorDisabledResponse::class => Responses\TwoFactorDisabledResponse::class,
        ];

        foreach ($responseBindings as $contract => $implementation) {
            $this->app->singleton($contract, $implementation);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureLoginPipeline();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        if (Features::enabled(Features::emailVerification())) {
            VerifyEmail::createUrlUsing(function ($notifiable) {
                return URL::temporarySignedRoute(
                    'verify-email',
                    Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
                    ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())]
                );
            });
        }
    }

    /**
     * Configure the login authentication pipeline.
     */
    private function configureLoginPipeline(): void
    {
        Fortify::authenticateThrough(function (Request $request) {
            return array_filter([
                config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
                Features::enabled(Features::emailVerification()) ? EnsureEmailIsVerified::class : null,
                Features::enabled(Features::twoFactorAuthentication()) ? RedirectsIfTwoFactorAuthenticatable::class : null,
                AttemptToAuthenticate::class,
                PrepareAuthenticatedSession::class,
            ]);
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        //        Fortify::loginView(fn (Request $request) => Inertia::render('Auth/Login', [
        //            'canResetPassword' => Features::enabled(Features::resetPasswords()),
        //            'canRegister' => Features::enabled(Features::registration()),
        //            'status' => $request->session()->get('status')
        //        ]));

        //        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
        //            'email' => $request->email,
        //            'token' => $request->route('token'),
        //        ]));
        //
        //        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
        //            'status' => $request->session()->get('status'),
        //        ]));

        //        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
        //            'status' => $request->session()->get('status'),
        //        ]));

        //        Fortify::registerView(fn () => Inertia::render('Auth/Register'));

        //        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));
        //
        //        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
