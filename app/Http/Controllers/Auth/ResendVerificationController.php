<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ResendVerificationController extends Controller
{
    /**
     * Display the "resend verification email" page.
     *
     * Renders the Inertia view where users can request a new verification
     * email by providing their username and email address.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        return Inertia::render('Auth/ResendVerification');
    }

    /**
     * Handle a "resend verification email" request.
     *
     * Requires both username and email to match the same user, making it
     * harder to enumerate accounts. Only sends the verification email if
     * the matched user has not yet verified. Always returns the same generic
     * success flash regardless of outcome to prevent information leakage.
     */
    public function store(Request $request): SymfonyResponse
    {
        precognitive(function () use ($request) {
            $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:'.User::NAME_MAX,
                    'min:'.User::NAME_MIN,
                ],
                'email' => [
                    'required',
                    'string',
                    'email:rfc,dns',
                    'max:255',
                ],
            ]);
        });

        $user = User::where('email', $request->email)
            ->where('name', $request->name)
            ->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        $request->session()->flash('message', __('passwords.verification_sent'));
        $request->session()->flash('type', 'success');

        return redirect('/');
    }
}
