<?php

namespace App\Http\Requests\Decks;

use App\Enums\DeckZone;
use App\Models\Deck;
use App\Models\DeckCard;
use Closure;
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
     * Sideboard cards cannot be assigned to a custom category — the UI
     * hides the "Move to Group" action for them, so reaching this path
     * with a non-null `category_id` while the resulting zone is `side`
     * means the request was tampered with. Plain 422 is enough; no
     * end-user-facing message is needed.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'nullable',
                'uuid',
                'exists:deck_categories,id',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }
                    $deckCard = $this->route('deckCard');
                    $effectiveZone = $this->input('zone', $deckCard?->zone->value);
                    if ($effectiveZone === DeckZone::Side->value) {
                        $fail('Sideboard cards cannot be assigned to a custom group.');
                    }
                },
            ],
            'zone' => ['sometimes', Rule::enum(DeckZone::class)],
        ];
    }
}
