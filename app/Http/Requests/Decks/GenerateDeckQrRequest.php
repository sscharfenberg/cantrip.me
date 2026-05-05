<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;

class GenerateDeckQrRequest extends FormRequest
{
    /**
     * When a deck is provided, the authenticated user must own it.
     * QR generation is owner-only even though the deck show page may be public —
     * the QR sticker is a tool the deck *creator* prints for their physical box.
     */
    public function authorize(): bool
    {
        $deck = $this->route('deck');

        if (! $deck) {
            return true;
        }

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
