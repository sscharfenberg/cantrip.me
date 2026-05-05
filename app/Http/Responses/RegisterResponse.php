<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    /**
     * Create the response for a successful registration.
     *
     * When email verification is enabled, logs the user back out
     * (undoing Fortify's auto-login) and redirects to the welcome page.
     * When disabled, keeps the session and redirects to the dashboard.
     *
     * @param  mixed  $request
     */
    public function toResponse($request): JsonResponse|Response
    {
        $request->session()->flash('message', __('auth.registered'));
        $request->session()->flash('type', 'success');

        if (Features::enabled(Features::emailVerification())) {
            Auth::logout();

            return redirect()->route('welcome');
        }

        return redirect(config('fortify.home'));
    }
}
