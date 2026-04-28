<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the deck-settings edit page: only the deck owner may open it.
 *
 * Sibling of {@see ShowDeckRequest}, which was relaxed to allow public
 * decks to be shown to anyone with the link. Editing remains strictly
 * owner-only — the relaxed show authorize() must not leak into the edit
 * flow, hence the separate request class.
 */
class EditDeckRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deck = $this->route('deck');

        return $deck instanceof Deck && $deck->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
