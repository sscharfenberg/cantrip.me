<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyEmailController extends Controller
{
    /**
     * Mark a user's email address as verified.
     *
     * Handles the signed verification URL sent by email. Does not require
     * authentication — the user is identified by the signed {id} and {hash}
     * route parameters, allowing verification even if the user is not logged in.
     * Dispatches the Verified event on first verification and redirects to login.
     *
     * @param  string  $id  The user's primary key from the signed URL.
     * @param  string  $hash  SHA-1 hash of the user's email for integrity verification.
     */
    public function __invoke(Request $request, string $id, string $hash): Response
    {
        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, 'Invalid verification link.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        $request->session()->flash('message', __('auth.email_verified'));
        $request->session()->flash('type', 'success');

        return redirect()->route('login');
    }
}
