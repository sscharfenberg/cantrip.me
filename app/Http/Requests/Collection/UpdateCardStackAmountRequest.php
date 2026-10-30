<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use Illuminate\Contracts\Validation\Validator;
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

    /**
     * Amount lock: a stack claimed by a deck keeps its amount — it is the
     * deck's business, and the tables offer no +/- for it. Reported on
     * `delta`, the one field this request has. See
     * {@see UpdateCardStackRequest::withValidator} for the edit form's twin.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $cardStack = $this->route('cardStack');
            if ($cardStack instanceof CardStack && $cardStack->isClaimed()) {
                $validator->errors()->add(
                    'delta',
                    __('collection.errors.cannot_change_amount_of_claimed_stack'),
                );
            }
        });
    }
}
