<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDeckCardPrintingRequest extends FormRequest
{
    /**
     * The user may change a card's printing only on their own deck,
     * and only for a card that belongs to that deck.
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
            'default_card_id' => ['required', 'uuid', Rule::exists('default_cards', 'id')],
        ];
    }

    /**
     * Ensure the chosen printing belongs to the deck card's oracle card —
     * otherwise a different card could be silently swapped in.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $deckCard = $this->route('deckCard');
            $defaultCardId = $this->input('default_card_id');

            if (! $deckCard instanceof DeckCard || ! is_string($defaultCardId)) {
                return;
            }

            $defaultCard = DefaultCard::query()->find($defaultCardId);

            if ($defaultCard === null || $defaultCard->oracle_id !== $deckCard->oracle_card_id) {
                $validator->errors()->add('default_card_id', 'The selected printing does not belong to this card.');
            }
        });
    }
}
