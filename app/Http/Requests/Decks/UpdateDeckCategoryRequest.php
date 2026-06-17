<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\DeckCategory;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeckCategoryRequest extends FormRequest
{
    /**
     * The user may rename a category only on their own deck, and only
     * for a category that belongs to that deck.
     */
    public function authorize(): bool
    {
        $deck = $this->route('deck');
        $deckCategory = $this->route('deckCategory');

        return $deck instanceof Deck
            && $deckCategory instanceof DeckCategory
            && $deck->user_id === $this->user()->id
            && $deckCategory->deck_id === $deck->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.DeckCategory::NAME_MAX],
        ];
    }
}
