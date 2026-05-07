<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;

class ShowDeckCompanionPrintingsRequest extends FormRequest
{
    /**
     * The user may list companion printings only on their own deck,
     * and only when a companion is actually set.
     */
    public function authorize(): bool
    {
        $deck = $this->route('deck');

        return $deck instanceof Deck
            && $deck->user_id === $this->user()->id
            && $deck->companion()->exists();
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
