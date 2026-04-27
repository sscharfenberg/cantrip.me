<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the "use the companion as the deck's hero image" action:
 * only the deck owner may change it, and the deck must actually have
 * a companion attached.
 */
class SetDeckCompanionHeroImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deck = $this->route('deck');

        return $deck instanceof Deck
            && $deck->user_id === $this->user()->id
            && $deck->companion_default_card_id !== null;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
