<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Traits\MovesFreedStacks;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the deck page's "Unclaim" on a card: frees the collection
 * stacks claimed for this one deck card. Only on the user's own deck, for
 * a card of that deck.
 *
 * The dialog can move the freed stacks in the same step (`move_to`, see
 * {@see MovesFreedStacks}) and take the card out of the deck as well
 * (`remove_from_deck`).
 */
class UnclaimDeckCardRequest extends FormRequest
{
    use MovesFreedStacks;

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
            'remove_from_deck' => ['sometimes', 'boolean'],
        ];
    }
}
