<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Services\DeckCollectionModeService;
use App\Services\DeckCollectionStatusService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorises and validates the per-deck "set collection mode" action
 * fired from the collection-mode modal's radio + submit.
 *
 * Owner-only. The body must include `mode` set to one of A / B / C.
 * Transition semantics (including the C → B/A cascade-delete of
 * pivot rows) live in {@see DeckCollectionModeService}.
 */
class SetDeckCollectionModeRequest extends FormRequest
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
        return [
            'mode' => ['required', Rule::in([
                DeckCollectionStatusService::MODE_A,
                DeckCollectionStatusService::MODE_B,
                DeckCollectionStatusService::MODE_C,
            ])],
        ];
    }
}
