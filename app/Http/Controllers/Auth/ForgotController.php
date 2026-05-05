<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ForgotUsernameNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class ForgotController extends Controller
{
    /**
     * Display the "Forgot password / username" page.
     *
     * Renders the Inertia view where users can request a password reset link
     * or a username reminder by providing their email address.
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Auth/Forgot');
    }

    /**
     * Handle a "forgot password" or "forgot username" form submission.
     *
     * Validates the request and dispatches to the appropriate handler based
     * on the selected type. Uses precognitive validation for real-time
     * frontend feedback.
     */
    public function store(Request $request): RedirectResponse
    {
        precognitive(function () use ($request) {
            $request->validate([
                'type' => ['required', 'in:password,name'],
                'email' => [
                    'required',
                    'string',
                    'email:rfc,dns',
                    'max:255',
                ],
                'name' => [
                    'required_if:type,password',
                    'string',
                    'max:'.User::NAME_MAX,
                    'min:'.User::NAME_MIN,
                ],
            ]);
        });

        return match ($request->type) {
            'password' => $this->sendPasswordResetLink($request),
            'name' => $this->sendUsernameReminder($request),
        };
    }

    /**
     * Send a password reset link via Fortify's password broker.
     *
     * Always returns a success flash regardless of whether the email exists,
     * preventing email enumeration.
     */
    private function sendPasswordResetLink(Request $request): RedirectResponse
    {
        $user = User::where('email', $request->get('email'))
            ->where('name', $request->get('name'))
            ->first();

        // only send password reset link if a user with that name and email exists.
        if ($user) {
            Password::broker(config('fortify.passwords'))
                ->sendResetLink($request->only('email'));
        }

        // Always return success to prevent email enumeration
        $request->session()->flash('message', __('passwords.sent'));
        $request->session()->flash('type', 'success');

        return redirect('/');
    }

    /**
     * Send a username reminder notification to the given email address.
     *
     * Only dispatches the notification if a matching user is found.
     * Always returns a success flash regardless, preventing email enumeration.
     */
    private function sendUsernameReminder(Request $request): RedirectResponse
    {
        $user = User::where('email', $request->email)->first();

        // only send username reminder if a user with that email exists.
        if ($user) {
            $user->notify(new ForgotUsernameNotification);
        }

        $request->session()->flash('message', __('passwords.username_sent'));
        $request->session()->flash('type', 'success');

        return redirect('/');
    }
}
