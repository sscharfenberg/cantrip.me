<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

class ProfileInformationUpdatedResponse implements ProfileInformationUpdatedResponseContract
{
    /**
     * Create the response for a successful profile information update.
     *
     * When email verification is enabled and the email changed (verification
     * cleared), logs the user out and redirects to login. Otherwise redirects
     * back with a success flash.
     *
     * @param  mixed  $request
     */
    public function toResponse($request): JsonResponse|Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 200);
        }

        if (Features::enabled(Features::emailVerification()) &&
            is_null($request->user()->email_verified_at)) {
            Auth::logout();

            $request->session()->flash('message', __('auth.profile_updated_email'));
            $request->session()->flash('type', 'success');

            return redirect()->route('login');
        }

        $request->session()->flash('message', __('auth.profile_updated'));
        $request->session()->flash('type', 'success');

        return back();
    }
}
