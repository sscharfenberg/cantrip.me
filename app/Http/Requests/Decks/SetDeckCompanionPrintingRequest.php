<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\DefaultCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SetDeckCompanionPrintingRequest extends FormRequest
{
    /**
     * The user may change the companion printing only on their own deck,
     * and only when a companion is actually set.
     */
    public function authorize(): bool
    {
        $deck = $this->route('deck');

        return $deck instanceof Deck
            && $deck->user_id === $this->user()->id
            && $deck->companion()->exists();
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'default_card_id' => ['required', 'uuid', Rule::exists('default_cards', 'id')],
        ];
    }

    /**
     * Ensure the chosen printing actually belongs to the deck's companion
     * oracle card — otherwise a different card could be silently swapped in.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $deck = $this->route('deck');
            $defaultCardId = $this->input('default_card_id');

            if (! $deck instanceof Deck || ! is_string($defaultCardId)) {
                return;
            }

            $defaultCard = DefaultCard::query()->find($defaultCardId);

            $companionOracleId = $deck->companion?->oracle_card_id;
            if ($defaultCard === null || $defaultCard->oracle_id !== $companionOracleId) {
                $validator->errors()->add('default_card_id', 'The selected printing does not belong to the companion.');
            }
        });
    }
}
