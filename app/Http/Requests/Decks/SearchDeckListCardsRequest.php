<?php

namespace App\Http\Requests\Decks;

use App\Enums\CardFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the deck list import's replacement search — the deck-less
 * counterpart of the deck-scoped card search, since the deck does not exist
 * while the review page is open.
 */
class SearchDeckListCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::enum(CardFormat::class)],
            'q' => ['required', 'string', 'max:150'],
            'quantity' => ['sometimes', 'integer', 'between:1,999'],
        ];
    }
}
