<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ConfirmPasswordController extends Controller
{
    /**
     * Validate the user's password and mark it as confirmed in the session.
     *
     * Sets `auth.password_confirmed_at` so that Fortify's `password.confirm`
     * middleware will pass for subsequent requests within the configured timeout.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($request->password, $request->user()->password)) {
            return response()->json([
                'errors' => ['password' => [__('auth.password')]],
            ], 422);
        }

        $request->session()->passwordConfirmed();

        return response()->json(['confirmed' => true]);
    }
}
