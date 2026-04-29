<?php

namespace App\Http\Requests\Decks;

use App\Enums\DeckState;
use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorises the deck-state quick toggle: only the owner may change a
 * deck's state. Used by mode-A's "Set to finished" action menu entry
 * (which bypasses the finalize wizard) and reusable for future planned
 * → archived transitions.
 */
class SetDeckStateRequest extends FormRequest
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
        return [
            'state' => ['required', Rule::enum(DeckState::class)],
        ];
    }
}
