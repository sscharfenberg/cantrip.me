<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;

class DeckQrSvgRequest extends FormRequest
{
    /**
     * The authenticated user must own the deck. Owner-only by design even for
     * public decks: the QR sticker is the owner's printout, not a share link.
     */
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
        return [];
    }
}
