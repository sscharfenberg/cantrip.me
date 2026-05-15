<?php

namespace App\Http\Requests\Decks;

use App\Enums\DeckState;
use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorises the deck-state quick toggle: only the owner may change a
 * deck's state. Free-flip endpoint — any of `planned` / `built` /
 * `archived` can move to any other, driven by the deck-actions menu.
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
