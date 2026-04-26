<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\DeckCard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the "use this card as the deck's hero image" action: only
 * the deck owner may change it, and the target deck card must actually
 * belong to the deck (so a tampered URL can't borrow another deck's
 * printing for the banner).
 */
class SetDeckHeroImageRequest extends FormRequest
{
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
