<?php

namespace App\Http\Requests\Collection;

use App\Enums\CardCondition;
use App\Enums\CardLanguage;
use App\Enums\Finish;
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
            'default_card_id' => ['required', Rule::exists(DefaultCard::class, 'id')],
            'amount' => ['required', 'integer', 'min:1', 'max:65535'],
            'language' => ['required', Rule::enum(CardLanguage::class)],
            'container_id' => ['nullable', Rule::exists(Container::class, 'id')],
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
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $cardId = $this->input('default_card_id');
            $finish = $this->input('finish');
            if (! is_string($cardId) || ! is_string($finish)) {
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
