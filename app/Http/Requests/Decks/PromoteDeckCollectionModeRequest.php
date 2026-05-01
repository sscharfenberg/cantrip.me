<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the "Switch to per-copy tracking" action — only the deck
 * owner may pin the collection mode to C. Idempotent at the service
 * layer; this request only checks ownership.
 */
class PromoteDeckCollectionModeRequest extends FormRequest
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
        return [];
    }
}
