<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the welcome page's Oracle card lookup.
 *
 * Public endpoint — Scryfall card data is not sensitive. `q` is the raw
 * search string, `set:` / `number:` tokens included; anything too short to
 * search on yields an empty result rather than an error.
 */
class LookupOracleCardsRequest extends FormRequest
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
            'q' => ['required', 'string', 'max:100'],
        ];
    }
}
