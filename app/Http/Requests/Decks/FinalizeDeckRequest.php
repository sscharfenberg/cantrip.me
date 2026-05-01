<?php

namespace App\Http\Requests\Decks;

use App\Models\Container;
use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorises the planned→built finalize wizard. Only the deck owner may
 * open or submit the wizard; the validation rules cover both the GET
 * (no payload) and the POST (assignment payload + optional deckbox +
 * optional "bought new" map).
 *
 * The shape of `assignments` is `[deck_card_id => [card_stack_id, ...]]`
 * — a per-deck-card list of stacks the user wants to claim. Empty list
 * is valid (user is skipping that row); empty whole map is valid too
 * (user is skipping the wizard altogether).
 *
 * The shape of `buy_new` is `[deck_card_id => bool]` — when true, the
 * service pads the row with a freshly-created card_stack covering any
 * uncovered slots and attaches it via the pivot. See
 * {@see DeckFinalizeService::persistAssignments} for the math.
 */
class FinalizeDeckRequest extends FormRequest
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
            'assignments' => ['nullable', 'array'],
            'assignments.*' => ['array'],
            'assignments.*.*' => ['uuid'],
            'buy_new' => ['nullable', 'array'],
            'buy_new.*' => ['boolean'],
            'container_id' => [
                'nullable',
                Rule::exists(Container::class, 'id')
                    ->where('user_id', $this->user()?->id),
            ],
        ];
    }
}
