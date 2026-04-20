<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\DeckCard;
use Illuminate\Foundation\Http\FormRequest;

class ShowDeckCardPrintingsRequest extends FormRequest
{
    /**
     * The user may list printings only for a deck card on their own deck,
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
        return [];
    }
}
