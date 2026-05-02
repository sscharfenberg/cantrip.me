<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the deck-card preview JSON endpoint.
 *
 * Public endpoint — Scryfall card data is not sensitive. The optional
 * `quantity` query parameter lets callers ask the server to also ship
 * `amount` and `total_price` (single-card price × quantity), used by
 * the deck-side preview to surface the deck's copy count and the
 * implied total.
 */
class ShowDefaultCardPreviewRequest extends FormRequest
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
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }
}
