<?php

namespace App\Http\Requests\Decks;

use App\Models\CardStack;
use App\Models\Deck;
use App\Models\DeckCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDeckCardAssignedStacksRequest extends FormRequest
{
    /**
     * Only the deck owner may change the assignment, and the deck card
     * must belong to the deck in the route.
     */
    public function authorize(): bool
    {
        $deck = $this->route('deck');
        $deckCard = $this->route('deckCard');

        return $deck instanceof Deck
            && $deckCard instanceof DeckCard
            && $deck->user_id === $this->user()->id
            && $deckCard->deck_id === $deck->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'card_stack_id' => ['nullable', 'uuid', Rule::exists('card_stacks', 'id')],
        ];
    }

    /**
     * Verify that the chosen stack is usable for this assignment:
     *
     *  - belongs to the requesting user (no claiming someone else's stack);
     *  - matches the deck_card's printing (mode C is printing-faithful —
     *    swap the deck_card's printing first if the user wants a different
     *    one);
     *  - isn't already pivoted to a *different* deck_card (the picker is
     *    replace-style for one deck_card only, never a steal across decks).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $deckCard = $this->route('deckCard');
            $stackId = $this->input('card_stack_id');

            if ($stackId === null || $stackId === '') {
                return;
            }
            if (! $deckCard instanceof DeckCard || ! is_string($stackId)) {
                return;
            }

            $stack = CardStack::query()->find($stackId);
            if ($stack === null) {
                return;
            }

            if ($stack->user_id !== $this->user()->id) {
                $validator->errors()->add('card_stack_id', __('pages.deck.assign_stack.errors.not_owned'));

                return;
            }
            if ($stack->default_card_id !== $deckCard->default_card_id) {
                $validator->errors()->add('card_stack_id', __('pages.deck.assign_stack.errors.wrong_printing'));

                return;
            }

            $foreignClaim = DB::table('deck_card_card_stack')
                ->where('card_stack_id', $stackId)
                ->where('deck_card_id', '!=', $deckCard->id)
                ->exists();
            if ($foreignClaim) {
                $validator->errors()->add('card_stack_id', __('pages.deck.assign_stack.errors.already_claimed'));
            }
        });
    }
}
