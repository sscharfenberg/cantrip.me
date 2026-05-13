<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    /**
     * Create the response for a successful two-factor challenge.
     *
     * The 2FA challenge is submitted via fetch() with Accept: application/json,
     * so the same locale problem as LoginResponse applies: ConfigureLocale
     * middleware runs before the challenge is verified, leaving Auth::user()
     * null at that point and the locale at the browser/session default.
     * Re-set it from the user's stored preference before responding.
     *
     * @param  mixed  $request
     * @return Response
     */
    public function toResponse($request)
    {
        // ConfigureLocale runs before authentication completes, so the locale
        // defaults to browser/session. Re-set it from the user's stored preference
        // now that $request->user() is available.
        if ($user = $request->user()) {
            app()->setLocale($user->locale->value);
        }

        if ($request->wantsJson()) {
            return new JsonResponse([
                'redirect' => redirect()->intended(Fortify::redirects('login'))->getTargetUrl(),
            ]);
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
