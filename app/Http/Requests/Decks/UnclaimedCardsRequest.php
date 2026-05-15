<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Services\DeckCollectionStatusService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises GET access to the UnclaimedCardStacks page and its CSV
 * export. Owner-only AND gated to modes B and C — mode A has no
 * collection plumbing, so the "what's unclaimed" question is silent.
 */
class UnclaimedCardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deck = $this->route('deck');
        if (! $deck instanceof Deck) {
            return false;
        }
        if ($deck->user_id !== $this->user()?->id) {
            return false;
        }

        $mode = DeckCollectionStatusService::effectiveMode($this->user(), $deck);

        return $mode === DeckCollectionStatusService::MODE_B
            || $mode === DeckCollectionStatusService::MODE_C;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
