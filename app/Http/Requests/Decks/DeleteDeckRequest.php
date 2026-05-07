<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the deck-delete action: only the owner may remove their deck.
 *
 * All deck-dependent tables (deck_cards, deck_categories) cascade on delete
 * via their foreign-key constraints, so no extra cleanup is needed on the
 * controller side beyond calling `$deck->delete()`.
 */
class DeleteDeckRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deck = $this->route('deck');

        return $deck instanceof Deck && $deck->user_id === $this->user()->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
