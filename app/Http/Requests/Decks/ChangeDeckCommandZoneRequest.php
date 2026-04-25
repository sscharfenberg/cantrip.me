<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\OracleCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorises replacing a deck's command zone. Only the deck's owner may
 * change it. Structural validation only — domain validation (legal
 * commander, valid pairings) lives in {@see DeckService::setCommandZone}.
 */
class ChangeDeckCommandZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deck = $this->route('deck');

        return $deck instanceof Deck && $deck->user_id === $this->user()->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'commander_id' => ['required', 'string', Rule::exists(OracleCard::class, 'id')],
            'companion_id' => ['nullable', 'string', Rule::exists(OracleCard::class, 'id')],
            'signature_spell_id' => ['nullable', 'string', Rule::exists(OracleCard::class, 'id')],
        ];
    }
}
