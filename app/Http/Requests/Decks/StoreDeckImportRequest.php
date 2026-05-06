<?php

namespace App\Http\Requests\Decks;

use App\Enums\CardFormat;
use App\Enums\DeckImportSource;
use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorises and validates the deck CSV import POST.
 *
 * Both source paths mint a brand-new deck — `format` is always
 * required so we know what kind of deck to create. `deck_name`
 * is optional; when omitted, the controller resolves a name from
 * the import results (commander name for commander-like formats,
 * "Imported Deck {timestamp}" otherwise).
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
            'deck_name' => ['nullable', 'string', 'max:'.Deck::NAME_MAX],
            'filename' => ['required', 'string', 'regex:/^[a-f0-9\-]{36}\.csv$/'],
        ];
    }
}
