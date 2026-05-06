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
 * The shape of the form depends on the source format:
 *
 *  - **cantrip** creates a brand-new deck — `format` is required so
 *    we know what kind of deck to mint; everything else uses sensible
 *    defaults (name="Imported Deck", visibility=private, no bracket).
 *    No `deck` target is needed.
 *  - **archidekt** imports into an existing deck the user owns. The
 *    deck doesn't need to be empty — Archidekt rows are added to
 *    whatever is already there, which is intentional (a user might
 *    keep a specific printing pinned before topping the deck up from
 *    an Archidekt export).
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
        $source = DeckImportSource::tryFrom((string) $this->input('source', ''));

        $rules = [
            'source' => ['required', Rule::enum(DeckImportSource::class)],
            'filename' => ['required', 'string', 'regex:/^[a-f0-9\-]{36}\.csv$/'],
        ];

        if ($source === DeckImportSource::Cantrip) {
            $rules['format'] = ['required', Rule::enum(CardFormat::class)];

            return $rules;
        }

        // Archidekt: existing target deck, owned. Card count is not
        // restricted — additive imports into populated decks are
        // explicitly allowed.
        $rules['deck'] = [
            'required',
            Rule::exists(Deck::class, 'id')->where('user_id', $this->user()->id),
        ];

        return $rules;
    }
}
