<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorDisabledResponse implements TwoFactorDisabledResponseContract
{
    /**
     * Create the response for a successful two-factor authentication deactivation.
     *
     * The user is already authenticated when this request arrives, so
     * ConfigureLocale correctly resolves their locale from the database —
     * no manual locale override is needed here.
     *
     * @param  mixed  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 200);
        }

        $request->session()->flash('message', __('auth.two_factor_deactivated'));
        $request->session()->flash('type', 'success');

        return back();
    }
}
