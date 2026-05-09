<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ForgotController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\ResendVerificationController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\User\ConfirmPasswordController;
use App\Http\Controllers\User\DeleteAccountController;
use App\Http\Middleware\HandleControllerPrecognitiveRequest;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\ProfileInformationController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;

/******************************************************************************
 * Auth pages, not logged in
 *****************************************************************************/
Route::middleware(['guest:'.config('fortify.guard')])->group(function () {
    Route::get('/login', [AuthController::class, 'loginView'])
        ->name('login');

    if (Features::enabled(Features::registration())) {
        Route::get('/register', [AuthController::class, 'registerView'])
            ->name('register');
    }

    if (Features::enabled(Features::resetPasswords())) {
        Route::get('/forgot', [ForgotController::class, 'show'])
            ->name('forgot');
        Route::post('/forgot', [ForgotController::class, 'store'])
            ->middleware(['throttle:6,1', HandleControllerPrecognitiveRequest::class])
            ->name('forgot.store');
        Route::get('reset-password', [NewPasswordController::class, 'show'])
            ->name('password.reset');
        Route::post('reset-password', [NewPasswordController::class, 'store'])
            ->middleware([HandleControllerPrecognitiveRequest::class])
            ->name('password.reset.store');
    }

    if (Features::enabled(Features::emailVerification())) {
        Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verify-email');
        Route::get('resend-verification', [ResendVerificationController::class, 'show'])
            ->name('verification.resend');
        Route::post('resend-verification', [ResendVerificationController::class, 'store'])
            ->middleware(['throttle:6,1', HandleControllerPrecognitiveRequest::class])
            ->name('verification.resend.store');
    }
});

/******************************************************************************
 * Auth actions requiring authentication
 *****************************************************************************/
Route::middleware(['auth'])->group(function () {
    // Password confirmation gate (sets session for Fortify's password.confirm middleware)
    Route::post('/confirm-password', [ConfirmPasswordController::class, 'store'])
        ->middleware(['throttle:6,1'])
        ->name('password.confirm');

    // Account deletion
    Route::delete('/user/delete', [DeleteAccountController::class, 'destroy'])
        ->middleware(['throttle:6,1'])
        ->name('user.delete');
});

/******************************************************************************
 * Fortify routes — manually registered because Fortify::ignoreRoutes() is
 * called in FortifyServiceProvider. Only routes actively used by this app are
 * included. The following Fortify defaults are intentionally omitted:
 *   POST /forgot-password                  (superseded by POST /forgot)
 *   GET  /email/verify/{id}/{hash}         (superseded by GET /verify-email/{id}/{hash})
 *   POST /email/verification-notification  (superseded by POST /resend-verification)
 *   POST /user/confirm-password            (superseded by POST /confirm-password)
 *   GET  /user/confirmed-password-status   (not used)
 *****************************************************************************/
Route::middleware([HandleControllerPrecognitiveRequest::class])->group(function () {
    // Login / Logout
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware(['guest:web', 'throttle:login'])
        ->name('login.store');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->middleware(['auth:web'])
        ->name('logout');

    // Registration
    if (Features::enabled(Features::registration())) {
        Route::post('/register', [RegisteredUserController::class, 'store'])
            ->middleware(['guest:web'])
            ->name('register.store');
    }

    // Two-Factor challenge (guest — completes login started in /login)
    if (Features::enabled(Features::twoFactorAuthentication())) {
        Route::post('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])
            ->middleware(['guest:web', 'throttle:two-factor'])
            ->name('two-factor.login.store');
    }

    // Profile information & password update (authenticated)
    if (Features::enabled(Features::updateProfileInformation())) {
        Route::put('/user/profile-information', [ProfileInformationController::class, 'update'])
            ->middleware(['auth:web'])
            ->name('user-profile-information.update');
    }
    if (Features::enabled(Features::updatePasswords())) {
        Route::put('/user/password', [PasswordController::class, 'update'])
            ->middleware(['auth:web'])
            ->name('user-password.update');
    }

    // Two-Factor Authentication management (authenticated; password.confirm when enabled)
    if (Features::enabled(Features::twoFactorAuthentication())) {
        $twoFactorMiddleware = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword')
            ? ['auth:web', 'password.confirm']
            : ['auth:web'];

        Route::post('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.enable');
        Route::post('/user/confirmed-two-factor-authentication', [ConfirmedTwoFactorAuthenticationController::class, 'store'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.confirm');
        Route::delete('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.disable');
        Route::get('/user/two-factor-qr-code', [TwoFactorQrCodeController::class, 'show'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.qr-code');
        Route::get('/user/two-factor-secret-key', [TwoFactorSecretKeyController::class, 'show'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.secret-key');
        Route::get('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'index'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.recovery-codes');
        Route::post('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'store'])
            ->middleware($twoFactorMiddleware)
            ->name('two-factor.regenerate-recovery-codes');
    }
});
