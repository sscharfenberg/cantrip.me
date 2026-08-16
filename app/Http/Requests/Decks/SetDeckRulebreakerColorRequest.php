<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Rulebreakers\RulebreakerRegistry;
use App\Rulebreakers\TolabowProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Authorises and validates the per-deck "nominate a colour" action fired from
 * the Rulebreaker popover.
 *
 * Only Tolabow, Loch Rascal asks for one today: "the color identity of instant
 * and sorcery cards in your deck can include one color of your choice not in
 * your commander's color identity".
 *
 * Owner-only. `color` is a single WUBRG letter, or null to clear the choice —
 * clearing is legal and simply withdraws the widening, which matters because a
 * pilot who picked red and then rebuilt the deck needs a way back.
 *
 * WHERE THE "not in your commander's color identity" CLAUSE IS ENFORCED. Here,
 * not in the profile. It constrains the CHOICE rather than the cards: nominating
 * a colour already in the identity is a no-op that widens to the same set, so
 * the rules engine has nothing to say about it, while the user typing it into a
 * picker deserves to be told. {@see TolabowProfile} therefore
 * tolerates such a value and this refuses to store one.
 */
class SetDeckRulebreakerColorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deck = $this->route('deck');

        return $deck instanceof Deck && $deck->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'color' => ['present', 'nullable', 'string', Rule::in(['W', 'U', 'B', 'R', 'G'])],
        ];
    }

    /**
     * Reject a colour the deck's commander cannot grant, and a deck whose
     * commander does not ask for one at all.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $deck = $this->route('deck');
            if (! $deck instanceof Deck) {
                return;
            }

            $deck->loadMissing('commanders.oracleCard');
            $profile = RulebreakerRegistry::forDeck($deck);

            $color = $this->input('color');
            $isClearing = ! is_string($color) || $color === '';

            // Clearing is allowed even when the deck's commander grants no
            // choice, and that is not a loophole. Swapping the commander away
            // from a Rulebreaker leaves the stored colour behind AND hides the
            // badge, so refusing this would freeze the column at a value with
            // no route to remove it — and swapping the Rulebreaker back in
            // would silently restore a colour the pilot never re-chose.
            if ($isClearing) {
                return;
            }

            if ($profile === null || ! $profile->requiresColorChoice()) {
                $validator->errors()->add('color', __('decks.rulebreaker.errors.no_choice_to_make'));

                return;
            }

            if (str_contains($deck->colorIdentity(), $color)) {
                $validator->errors()->add('color', __('decks.rulebreaker.errors.already_in_identity'));
            }
        });
    }
}
