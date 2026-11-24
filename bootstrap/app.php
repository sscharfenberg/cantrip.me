<?php

use App\Http\Middleware\ConfigureLocale;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/web.auth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so error pages (a 404 never reaches the web group) carry it too.
        $middleware->append(ContentSecurityPolicy::class);
        $middleware->web(append: [
            ConfigureLocale::class, // determine the correct locale
            HandleInertiaRequests::class, // handle inertia requests
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
