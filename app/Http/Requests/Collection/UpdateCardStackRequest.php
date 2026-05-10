<?php

namespace App\Http\Requests\Collection;

use App\Enums\CardCondition;
use App\Enums\CardLanguage;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:65535'],
            'language' => ['required', Rule::enum(CardLanguage::class)],
            'container_id' => ['nullable', Rule::exists(Container::class, 'id')],
            'condition' => ['nullable', Rule::enum(CardCondition::class)],
            'finish' => ['required', Rule::in(Finish::labels())],
            'proxy' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Two after-checks:
     *
     *  - **Container lock.** A stack claimed by a deck cannot be moved.
     *    The pivot relation enforces "this physical card lives where the
     *    deck card lives" — silently moving a claimed stack would desync
     *    the deck card's collection_status. The user must unclaim the
     *    stack first via the deck UI.
     *
     *  - **Finish-per-card.** The chosen finish must be one of the
     *    finishes the underlying default_card actually supports. The
     *    frontend only exposes valid finishes in the radio group, but a
     *    malformed POST could still slip through {@see Rule::in()} above
     *    (which only checks the master label list, not per-card support).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $cardStack = $this->route('cardStack');
            if (! $cardStack instanceof CardStack) {
                return;
            }

            // Container lock-check (only when the field is present and changed).
            if ($this->has('container_id')) {
                $newContainerId = $this->input('container_id') ?: null;
                if ($newContainerId !== $cardStack->container_id && $cardStack->isClaimed()) {
                    $validator->errors()->add(
                        'container_id',
                        __('collection.errors.cannot_move_claimed_stack'),
                    );
                }
            }

            // Finish-per-card check.
            $finish = $this->input('finish');
            if (is_string($finish)) {
                $card = $cardStack->defaultCard()->first(['id', 'finishes']);
                if ($card !== null) {
                    $available = Finish::labelsFromMask($card->finishes);
                    if (! in_array($finish, $available, true)) {
                        $validator->errors()->add(
                            'finish',
                            __('collection.errors.finish_not_available_for_card', [
                                'available' => implode(', ', $available),
                            ]),
                        );
                    }
                }
            }
        });
    }
}
