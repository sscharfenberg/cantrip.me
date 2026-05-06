<?php

namespace App\Http\Requests\Decks;

use App\Enums\CardFormat;
use App\Enums\DeckImportSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorises and validates the deck CSV import POST.
 *
 * Both source paths now mint a brand-new deck — `format` is always
 * required so we know what kind of deck to create. Everything else
 * uses sensible defaults (name="Imported Deck", visibility=private,
 * no bracket).
 */
class StoreDeckImportRequest extends FormRequest
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
            'source' => ['required', Rule::enum(DeckImportSource::class)],
            'format' => ['required', Rule::enum(CardFormat::class)],
            'filename' => ['required', 'string', 'regex:/^[a-f0-9\-]{36}\.csv$/'],
        ];
    }
}
