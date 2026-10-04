<?php

namespace App\Http\Requests\Decks;

use App\Enums\CardFormat;
use App\Http\Controllers\Decks\DecksController;
use App\Models\Deck;
use App\Models\OracleCard;
use Illuminate\Validation\Rule;

/**
 * Validation rules for the fields every new deck is created from.
 *
 * Shared by the create-deck form ({@see DecksController::store})
 * and the deck list import ({@see StoreDeckListImportRequest}), so a request
 * to either endpoint is held to the same rules — a hand-crafted import cannot
 * create a deck the form would refuse.
 *
 * "Is a commander required?" comes from the format's profile, never from a
 * list of format names, so a new commander-like format inherits it.
 */
final class DeckCreationRules
{
    /**
     * @return array<string, array<mixed>>
     */
    public static function for(?CardFormat $format): array
    {
        $profile = $format?->rules();
        $requiresCommander = $profile !== null && $profile->requiresCommander();
        $requiresSignatureSpell = $requiresCommander && $profile->hasSignatureSpell();
        $usesGameChangerList = $profile !== null && $profile->usesGameChangerList();

        return [
            'format' => ['required', 'string', Rule::enum(CardFormat::class)],
            'deck_name' => ['required', 'string', 'max:'.Deck::NAME_MAX],
            'deck_description' => ['nullable', 'string', 'max:'.Deck::DESCRIPTION_MAX],
            'bracket' => $usesGameChangerList
                ? ['nullable', 'integer', 'between:1,5']
                : ['prohibited'],
            'commander_id' => [
                $requiresCommander ? 'required' : 'nullable',
                'string',
                Rule::exists(OracleCard::class, 'id'),
            ],
            'companion_id' => ['nullable', 'string', Rule::exists(OracleCard::class, 'id')],
            'signature_spell_id' => [
                $requiresSignatureSpell ? 'required' : 'nullable',
                'string',
                Rule::exists(OracleCard::class, 'id'),
            ],
        ];
    }
}
