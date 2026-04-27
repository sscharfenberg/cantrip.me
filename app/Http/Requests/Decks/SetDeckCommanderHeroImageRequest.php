<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Models\OracleCard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the "use this commander as the deck's hero image" action:
 * only the deck owner may change it, and the target oracle card must
 * actually be one of this deck's commanders (so a tampered URL can't
 * borrow another deck's printing for the banner).
 */
class SetDeckCommanderHeroImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deck = $this->route('deck');
        $oracleCard = $this->route('oracleCard');

        return $deck instanceof Deck
            && $oracleCard instanceof OracleCard
            && $deck->user_id === $this->user()->id
            && $deck->commanders()->whereKey($oracleCard->id)->exists();
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
