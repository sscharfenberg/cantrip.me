<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Laravel\Fortify\Features;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * Enforces unique name and email constraints (ignoring the current user)
     * and project-specific length rules. When the email address changes on a
     * verified user, verification is revoked and a new verification
     * notification is dispatched.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => [
                'required',
                'string',
                'min:'.User::NAME_MIN,
                'max:'.User::NAME_MAX,
                Rule::unique('users')->ignore($user->id),
            ],
            'email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
        ])->validate();

        if ($input['email'] !== $user->email &&
            $user instanceof MustVerifyEmail &&
            Features::enabled(Features::emailVerification())) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
            ])->save();
        }
    }

    /**
     * Update a previously verified user's profile information.
     *
     * Clears the email verification timestamp and sends a fresh
     * verification notification so the user must re-verify the new address.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
