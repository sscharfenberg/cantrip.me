<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SplitDeckCardRequest extends FormRequest
{
    /**
     * The user may split a card's printings only on their own deck,
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
            'splits' => ['required', 'array', 'min:2'],
            'splits.*.default_card_id' => ['required', 'uuid', Rule::exists('default_cards', 'id')],
            'splits.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Enforce invariants that depend on relationships between the splits and
     * the deck card being split:
     *
     * - every chosen printing must belong to the deck card's oracle card
     *   (otherwise the split would silently swap in a different card);
     * - printings must be distinct (no two rows for the same default_card_id);
     * - the sum of the split quantities must equal the existing deck card's
     *   quantity — the split preserves the total number of copies and thus
     *   cannot violate format copy limits.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $deckCard = $this->route('deckCard');
            /** @var array<int, array{default_card_id?: string, quantity?: int}> $splits */
            $splits = $this->input('splits', []);

            if (! $deckCard instanceof DeckCard || ! is_array($splits) || count($splits) < 2) {
                return;
            }

            $ids = array_column($splits, 'default_card_id');
            if (count($ids) !== count(array_unique($ids))) {
                $validator->errors()->add('splits', 'Each printing may only appear once.');

                return;
            }

            $defaultCards = DefaultCard::query()->whereIn('id', $ids)->get();
            foreach ($defaultCards as $card) {
                if ($card->oracle_id !== $deckCard->oracle_card_id) {
                    $validator->errors()->add('splits', 'A chosen printing does not belong to this card.');

                    return;
                }
            }

            $sum = array_sum(array_column($splits, 'quantity'));
            if ($sum !== $deckCard->quantity) {
                $validator->errors()->add('splits', 'The split quantities must sum to the current quantity.');
            }
        });
    }
}
