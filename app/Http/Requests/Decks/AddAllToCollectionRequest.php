<?php

namespace App\Http\Requests\Decks;

use App\Models\Container;
use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorises the "Add all cards to collection" action — owner-only. The
 * payload carries an optional container_id (must belong to the user) and
 * a boolean flag for the optional planned→built transition.
 */
class AddAllToCollectionRequest extends FormRequest
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
            'container_id' => [
                'nullable',
                Rule::exists(Container::class, 'id')
                    ->where('user_id', $this->user()?->id),
            ],
            'set_built' => ['nullable', 'boolean'],
        ];
    }
}
