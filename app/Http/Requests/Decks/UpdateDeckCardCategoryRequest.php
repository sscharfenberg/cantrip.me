<?php

namespace App\Http\Requests\Decks;

use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeckCardCategoryRequest extends FormRequest
{
    /**
     * The user may reassign a card's category only on their own deck,
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
     * Accepts an optional `zone` alongside the category so drag+drop to
     * the sideboard bucket can flip main↔side in one call. When absent
     * the controller leaves the zone untouched.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'uuid', 'exists:deck_categories,id'],
            'zone' => ['sometimes', Rule::enum(DeckZone::class)],
        ];
    }
}
