<?php

namespace App\Http\Requests\Decks;

use App\Enums\ContainerVisibility;
use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorises the deck-visibility quick toggle: only the owner may flip
 * a deck between private and public. Validation accepts the raw enum
 * string so the route can be hit with a simple `{ visibility: 'public' }`
 * payload.
 */
class SetDeckVisibilityRequest extends FormRequest
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
            'visibility' => ['required', Rule::enum(ContainerVisibility::class)],
        ];
    }
}
