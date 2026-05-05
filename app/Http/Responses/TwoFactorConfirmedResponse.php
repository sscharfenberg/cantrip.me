<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorConfirmedResponse as TwoFactorConfirmedResponseContract;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorConfirmedResponse implements TwoFactorConfirmedResponseContract
{
    /**
     * Create the response for a successful two-factor authentication confirmation.
     *
     * This fires after the user scans the QR code and enters the TOTP code to
     * verify their authenticator is working. It is the final step of the 2FA
     * enrollment flow and the point at which 2FA is fully active.
     *
     * Unlike the login responses, the user is already authenticated when this
     * request arrives, so ConfigureLocale correctly resolves their locale from
     * the database — no manual locale override is needed here.
     *
     * @param  mixed  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 200);
        }

        $request->session()->flash('message', __('auth.two_factor_activated'));
        $request->session()->flash('type', 'success');

        return back();
    }
}
