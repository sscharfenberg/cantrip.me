<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the command-zone card searches (commander, oathbreaker).
 *
 * Public endpoints — Scryfall card data is not sensitive. The search
 * parameters themselves are read leniently by the controller (an unknown
 * format or type yields an empty result); only `exclude` is checked here,
 * because it reaches a query against a uuid column, and Postgres rejects a
 * malformed uuid with an error (a 500) rather than an empty result.
 */
class CommandZoneSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'exclude' => ['nullable', 'uuid'],
        ];
    }
}
