<?php

namespace App\Http\Requests\Decks;

use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorises a "move 1 copy to the other zone" operation: deck owner only,
 * card must belong to the deck, and the target zone must differ from the
 * card's current zone (no-op moves get a 422 — this only happens via
 * client tampering since the UI hides the action for that case).
 */
class MoveDeckCardZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deck = $this->route('deck');
        $deckCard = $this->route('deckCard');

        return $deck instanceof Deck
            && $deckCard instanceof DeckCard
            && $deck->user_id === $this->user()->id
            && $deckCard->deck_id === $deck->id
            && $deckCard->zone->value !== $this->input('zone');
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'zone' => ['required', Rule::enum(DeckZone::class)],
        ];
    }
}
