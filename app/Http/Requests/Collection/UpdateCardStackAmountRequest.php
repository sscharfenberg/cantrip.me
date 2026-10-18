<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the card-stack +/- buttons in the collection and container
 * tables' row actions: only the owner may change a stack's amount.
 *
 * `delta` is the net change of a burst of clicks, which the frontend
 * collects for a moment before it sends them as one. Whether the result
 * stays within 1…{@see CardStack::MAX_AMOUNT} is checked against the
 * locked row in {@see CardStackService::changeAmount}, not here: two
 * requests checked against the same snapshot could both pass.
 */
class UpdateCardStackAmountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cardStack = $this->route('cardStack');

        return $cardStack instanceof CardStack && $cardStack->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'delta' => ['required', 'integer', 'not_in:0', 'between:-'.CardStack::MAX_AMOUNT.','.CardStack::MAX_AMOUNT],
        ];
    }
}
