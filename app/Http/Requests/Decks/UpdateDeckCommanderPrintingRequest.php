<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\DefaultCard;
use App\Models\OracleCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Authorises swapping the displayed printing of one of a deck's commanders.
 * Owner of the deck only; the route's `oracleCard` must already be a
 * commander on that deck; and the chosen `default_card_id` must belong to
 * that commander's oracle card so a different card can't be silently
 * swapped in.
 */
class UpdateDeckCommanderPrintingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deck = $this->route('deck');
        $oracleCard = $this->route('oracleCard');

        return $deck instanceof Deck
            && $oracleCard instanceof OracleCard
            && $deck->user_id === $this->user()->id
            && $deck->commanders()->where('oracle_card_id', $oracleCard->id)->exists();
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $oracleCard = $this->route('oracleCard');
            $defaultCardId = $this->input('default_card_id');

            if (! $oracleCard instanceof OracleCard || ! is_string($defaultCardId)) {
                return;
            }

            $defaultCard = DefaultCard::query()->find($defaultCardId);
            if ($defaultCard === null || $defaultCard->oracle_id !== $oracleCard->id) {
                $validator->errors()->add('default_card_id', 'The selected printing does not belong to this commander.');
            }
        });
    }
}
