<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\OracleCard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises listing the printings of one of a deck's commanders. Owner of
 * the deck only, and the route's `oracleCard` must already be a commander
 * on that deck (so this can't be used to fish for printings of arbitrary
 * cards under the deck path).
 */
class ShowDeckCommanderPrintingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deck = $this->route('deck');
        $oracleCard = $this->route('oracleCard');

        return $deck instanceof Deck
            && $oracleCard instanceof OracleCard
            && $deck->user_id === $this->user()->id
            && $deck->commanders()->where('oracle_card_id', $oracleCard->id)->exists();
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
