<?php

namespace App\Http\Requests\Collection;

use App\Enums\CardCondition;
use App\Enums\CardLanguage;
use App\Enums\Finish;
use App\Models\CardStack;
use App\Models\Container;
use App\Models\DefaultCard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddCardStackRequest extends FormRequest
{
    /**
     * When a container is provided, the authenticated user must own it.
     */
    public function authorize(): bool
    {
        $container = $this->route('container');

        if (! $container) {
            return true;
        }

        return $container instanceof Container && $container->user_id === $this->user()->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'default_card_id' => ['required', 'uuid', Rule::exists(DefaultCard::class, 'id')],
            'amount' => ['required', 'integer', 'min:1', 'max:'.CardStack::MAX_AMOUNT],
            'language' => ['required', Rule::enum(CardLanguage::class)],
            'container_id' => ['nullable', 'uuid', Rule::exists(Container::class, 'id')],
            'condition' => ['nullable', Rule::enum(CardCondition::class)],
            'finish' => ['required', Rule::in(Finish::labels())],
            'proxy' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Belt-and-suspenders check: the chosen finish must be one of the
     * finishes the selected card actually supports. The frontend only
     * exposes valid finishes in the radio group, but a malformed POST
     * could still slip through Rule::in() above (which only checks the
     * master label list, not per-card support).
     *
     * After-hooks run even when a rule already failed, so a card id that
     * failed its own rules must not reach the query: Postgres rejects a
     * malformed uuid with an error (a 500), not an empty result.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $cardId = $this->input('default_card_id');
            $finish = $this->input('finish');
            if (! is_string($cardId) || ! is_string($finish) || $validator->errors()->has('default_card_id')) {
                return;
            }

            $card = DefaultCard::query()->find($cardId, ['id', 'finishes']);
            if ($card === null) {
                return;
            }

            $available = Finish::labelsFromMask($card->finishes);
            if (! in_array($finish, $available, true)) {
                $validator->errors()->add(
                    'finish',
                    __('collection.errors.finish_not_available_for_card', [
                        'available' => implode(', ', $available),
                    ]),
                );
            }
        });
    }
}
