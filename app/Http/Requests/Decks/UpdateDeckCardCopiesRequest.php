<?php

namespace App\Http\Requests\Decks;

use App\Enums\CardCondition;
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
 * Validates "Edit copies" on the deck page, for one group of a card's
 * copies: the copies no stack claims — their plan
 * ({@see DeckCardCopiesService::replan}) — or, with `stack_id`, the copies
 * of one stack claimed for the card, which are the collection's
 * ({@see DeckCardCopiesService::editClaimedStack}). `count` says how many
 * of them take the new values: all of them, or fewer, which splits them
 * off. `condition` is a physical card's, so it applies to a stack only;
 * left out, the stack keeps the one it has.
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
            'stack_id' => ['nullable', 'uuid'],
            'count' => ['required', 'integer', 'min:1'],
            'finish' => ['required', Rule::in(Finish::labels())],
            'language' => ['required', Rule::enum(CardLanguage::class)],
            'proxy' => ['required', 'boolean'],
            'condition' => ['nullable', Rule::enum(CardCondition::class)],
        ];
    }

    /**
     * What the rules can't express. For a stack: it is claimed for this card
     * — and claims exist for the user only while the collection master
     * switch is on — and holds that many copies. For the plan: there are
     * that many unclaimed copies to re-plan. While the master switch is off,
     * claims are invisible and every copy counts as planned; a card that has
     * claims can then only be re-planned whole, so no split moves copies away
     * from them. Either way the printing must exist in the finish — the
     * dialog only offers those, but a malformed request could ask for a foil
     * Alpha card.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $deckCard = $this->route('deckCard');
            if (! $deckCard instanceof DeckCard || $validator->errors()->isNotEmpty()) {
                return;
            }

            $count = (int) $this->input('count');
            $stackId = $this->input('stack_id');
            if ($stackId !== null) {
                $stack = $this->user()->collection_integration_enabled
                    ? $deckCard->cardStacks()
                        ->where('card_stacks.id', $stackId)
                        ->where('card_stacks.user_id', $this->user()->id)
                        ->with('defaultCard:id,finishes')
                        ->first()
                    : null;
                if ($stack === null) {
                    $validator->errors()->add('stack_id', __('decks.copies.errors.not_claimed'));

                    return;
                }
                if ($count > $stack->amount) {
                    $validator->errors()->add('count', __('decks.copies.errors.count'));
                }
                $mask = (int) $stack->defaultCard?->finishes;
            } else {
                $unclaimed = DeckCardCopiesService::unclaimedCount($deckCard);
                $tooMany = $this->user()->collection_integration_enabled
                    ? $count > $unclaimed
                    : $count > $deckCard->quantity || ($unclaimed < $deckCard->quantity && $count !== $deckCard->quantity);
                if ($tooMany) {
                    $validator->errors()->add('count', __('decks.copies.errors.count'));
                }
                $mask = (int) DefaultCard::query()->whereKey($deckCard->default_card_id)->value('finishes');
            }

            $finish = Finish::fromLabel((string) $this->input('finish'));
            if ($finish === null || ! $finish->isPresentIn($mask)) {
                $validator->errors()->add('finish', __('decks.copies.errors.finish'));
            }
        });
    }
}
