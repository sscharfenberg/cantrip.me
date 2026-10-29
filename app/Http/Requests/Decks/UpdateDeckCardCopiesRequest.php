<?php

namespace App\Http\Requests\Decks;

use App\Enums\CardLanguage;
use App\Enums\Finish;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\DefaultCard;
use App\Services\DeckCardCopiesService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates "Edit copies" on the deck page for the copies of a card that
 * no stack claims — their plan ({@see DeckCardCopiesService::replan}).
 * `count` says how many of them take the new values: all of them, or
 * fewer, which splits them off into a row of their own.
 */
class UpdateDeckCardCopiesRequest extends FormRequest
{
    /**
     * Only on the user's own deck, for a card of that deck.
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
            'count' => ['required', 'integer', 'min:1'],
            'finish' => ['required', Rule::in(Finish::labels())],
            'language' => ['required', Rule::enum(CardLanguage::class)],
            'proxy' => ['required', 'boolean'],
        ];
    }

    /**
     * Two checks the rules can't express: there must be that many unclaimed
     * copies to re-plan, and the card's printing must exist in the finish —
     * the modal only offers those, but a malformed request could ask for a
     * foil Alpha card.
     *
     * While the collection master switch is off, claims are invisible and
     * every copy counts as planned; a card that has claims can then only be
     * re-planned whole, so no split moves copies away from them.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $deckCard = $this->route('deckCard');
            if (! $deckCard instanceof DeckCard || $validator->errors()->isNotEmpty()) {
                return;
            }

            $count = (int) $this->input('count');
            $unclaimed = DeckCardCopiesService::unclaimedCount($deckCard);
            $tooMany = $this->user()->collection_integration_enabled
                ? $count > $unclaimed
                : $count > $deckCard->quantity || ($unclaimed < $deckCard->quantity && $count !== $deckCard->quantity);
            if ($tooMany) {
                $validator->errors()->add('count', __('decks.copies.errors.count'));
            }

            $finish = Finish::fromLabel((string) $this->input('finish'));
            $mask = (int) DefaultCard::query()->whereKey($deckCard->default_card_id)->value('finishes');
            if ($finish === null || ! $finish->isPresentIn($mask)) {
                $validator->errors()->add('finish', __('decks.copies.errors.finish'));
            }
        });
    }
}
