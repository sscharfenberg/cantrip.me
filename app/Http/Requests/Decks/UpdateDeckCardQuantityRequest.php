<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Traits\MovesFreedStacks;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates "−" / "+" on a deck card. Copies that leave can free claimed
 * copies; `move_to` (see {@see MovesFreedStacks}) says where those go when
 * the page asked.
 */
class UpdateDeckCardQuantityRequest extends FormRequest
{
    use MovesFreedStacks;

    /**
     * The user may change a card's quantity only on their own deck,
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
            ...$this->moveToRules(),
            'delta' => ['required', 'integer', 'not_in:0'],
        ];
    }
}
