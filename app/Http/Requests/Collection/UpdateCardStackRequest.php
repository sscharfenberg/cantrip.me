<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the card-stack update endpoint: only the owner may modify
 * a stack. The form-field `container_id` (target container) is checked
 * separately via {@see CardStackService::resolveOwnedContainer} since
 * that's a form-data concern, not a route-resource one.
 */
class UpdateCardStackRequest extends FormRequest
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
        return [];
    }

    /**
     * Block container moves on stacks that are claimed by a deck.
     *
     * The pivot relation enforces "this physical card lives where the
     * deck card lives" — silently moving a claimed stack would desync
     * the deck card's collection_status. The user must unclaim the
     * stack first via the deck UI.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $cardStack = $this->route('cardStack');
            if (! $cardStack instanceof CardStack) {
                return;
            }

            if (! $this->has('container_id')) {
                return;
            }

            $newContainerId = $this->input('container_id') ?: null;
            if ($newContainerId === $cardStack->container_id) {
                return;
            }

            if ($cardStack->isClaimed()) {
                $validator->errors()->add(
                    'container_id',
                    __('collection.errors.cannot_move_claimed_stack'),
                );
            }
        });
    }
}
