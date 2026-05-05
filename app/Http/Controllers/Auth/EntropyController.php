<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ZxcvbnPhp\Zxcvbn;

class EntropyController extends Controller
{
    /**
     * Calculate password entropy using zxcvbn.
     *
     * Provides a server-side strength estimate so the frontend can display
     * real-time feedback during registration or password changes.
     * Returns guess count, score (0-4), and estimated offline crack time.
     */
    public function calculate(Request $request): JsonResponse
    {
        $zxcvbn = new Zxcvbn;
        $password = $request->input('p');
        if (! is_null($password)) {
            $entropy = $zxcvbn->passwordStrength($password);

            return response()->json([
                'guesses' => $entropy['guesses'],
                'score' => $entropy['score'],
                'time' => $entropy['crack_times_seconds']['offline_slow_hashing_1e4_per_second'],
            ]);
        } else {
            return response()->json([], 422);
        }
    }
}
