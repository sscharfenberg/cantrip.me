<?php

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class LocaleController extends Controller
{
    /**
     * Switch the application locale.
     *
     * Validates the requested locale against the configured supported locales.
     * For authenticated users the preference is persisted to the database;
     * for guests it is stored in the session.
     *
     * @param  string  $locale  The locale code to switch to (e.g. "en", "de").
     */
    public function update(string $locale): JsonResponse
    {
        if (Locale::tryFrom($locale) === null) {
            return response()->json([], 422);
        }

        // update authenticated user locale, if available
        $userId = Auth::id();
        if ($userId) {
            $user = User::where('id', $userId)->first();
            $user->locale = $locale;
            $user->save();
        } else { // set session locale
            session(['locale' => $locale]);
        }

        app()->setLocale($locale);

        return response()->json(['locale' => $locale]);
    }
}
