<?php

namespace App\Traits;

use App\Rules\PasswordEntropy;
use Illuminate\Contracts\Validation\Rule;

trait PasswordValidationRules
{
    /**
     * Get the shared password validation rules.
     *
     * Centralises the password requirements (minimum length + entropy check)
     * so they stay consistent across registration and password reset flows.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', 'min:8', new PasswordEntropy];
    }
}
