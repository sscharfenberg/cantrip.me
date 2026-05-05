<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
    /**
     * Create the response for a successful password update.
     *
     * @param  mixed  $request
     */
    public function toResponse($request): JsonResponse|Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 200);
        }

        $request->session()->flash('message', __('passwords.updated'));
        $request->session()->flash('type', 'success');

        return back();
    }
}
