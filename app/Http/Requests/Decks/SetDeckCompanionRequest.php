<?php

namespace App\Http\Requests\Decks;

use App\Companions\CompanionRegistry;
use App\Models\Deck;
use App\Models\OracleCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SetDeckCompanionRequest extends FormRequest
{
    /**
     * The user may set a companion only on their own deck.
     */
    public function authorize(): bool
    {
        $deck = $this->route('deck');

        return $deck instanceof Deck
            && $deck->user_id === $this->user()->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'oracle_card_id' => ['required', 'uuid', Rule::exists(OracleCard::class, 'id')],
        ];
    }

    /**
     * Enforce the companion-specific rules: format allows the mechanic, chosen
     * card is one of the ten, not format-banned as companion, respects the
     * commanders' color identity, and is not already a commander on this deck.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $deck = $this->route('deck');
            $oracleCardId = $this->input('oracle_card_id');

            if (! $deck instanceof Deck || ! is_string($oracleCardId)) {
                return;
            }

            $profile = $deck->format->rules();

            if (! $profile->allowsCompanion()) {
                $validator->errors()->add('oracle_card_id', __('decks.companion.errors.not_allowed_in_format'));

                return;
            }

            $card = OracleCard::query()->find($oracleCardId);

            if ($card === null) {
                return;
            }

            if (! CompanionRegistry::isCompanion($card)) {
                $validator->errors()->add('oracle_card_id', __('decks.companion.errors.not_a_companion'));

                return;
            }

            if (in_array($card->name, $profile->bannedAsCompanion(), true)) {
                $validator->errors()->add('oracle_card_id', __('decks.companion.errors.banned_in_format'));

                return;
            }

            $deck->loadMissing('commanders');

            // `commanders` is now HasMany<DeckCard>, so the duplicate
            // check looks at the row's `oracle_card_id` column rather
            // than the OracleCard's `id`.
            if ($deck->commanders->contains('oracle_card_id', $oracleCardId)) {
                $validator->errors()->add('oracle_card_id', __('decks.companion.errors.already_commander'));

                return;
            }

            if ($profile->enforcesColorIdentity() && ! self::respectsCommanderIdentity($card->color_identity, $deck)) {
                $validator->errors()->add('oracle_card_id', __('decks.companion.errors.outside_color_identity'));
            }
        });
    }

    private static function respectsCommanderIdentity(?string $cardIdentity, Deck $deck): bool
    {
        if ($cardIdentity === null || $cardIdentity === '') {
            return true;
        }

        $deck->loadMissing('commanders.oracleCard:id,color_identity');

        $commanderLetters = [];
        foreach ($deck->commanders as $commanderRow) {
            $oracle = $commanderRow->oracleCard;
            if ($oracle === null || $oracle->color_identity === null) {
                continue;
            }
            foreach (str_split($oracle->color_identity) as $letter) {
                $commanderLetters[$letter] = true;
            }
        }

        foreach (str_split($cardIdentity) as $letter) {
            if (! isset($commanderLetters[$letter])) {
                return false;
            }
        }

        return true;
    }
}
