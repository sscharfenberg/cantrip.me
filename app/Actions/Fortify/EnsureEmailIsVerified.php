<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class EnsureEmailIsVerified
{
    /**
     * Ensure the user's email address has been verified before allowing login.
     *
     * @param  Request  $request
     *
     * @throws ValidationException
     */
    public function handle($request, Closure $next): mixed
    {
        if (! Features::enabled(Features::emailVerification())) {
            return $next($request);
        }

        $user = User::where(Fortify::username(), $request->{Fortify::username()})->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                Fortify::username() => [__('auth.email_not_verified')],
            ]);
        }

        return $next($request);
    }
}
