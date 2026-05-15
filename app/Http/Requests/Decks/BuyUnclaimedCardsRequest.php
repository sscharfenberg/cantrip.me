<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use App\Services\DeckCollectionStatusService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises POST /decks/{deck}/unclaimed/buy — the mode-C "I just
 * bought these" submission from the UnclaimedCardStacks page. Owner-
 * only AND gated to mode C; mode B has no buy semantics (the user
 * is expected to physically move cards into the deckbox container).
 *
 * Body shape: `{ bought: [deck_card_id, ...] }`. Each id is the
 * deck_card row that the user ticked. The controller resolves each
 * row's `unclaimed` count server-side from the deck_card's quantity
 * minus existing pivot coverage, then mints + claims a stack of that
 * size. The deck's `container_id` is used as the new stack's
 * container so freshly bought cards land in the deckbox.
 */
class BuyUnclaimedCardsRequest extends FormRequest
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

        return DeckCollectionStatusService::effectiveMode($this->user(), $deck)
            === DeckCollectionStatusService::MODE_C;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'bought' => ['required', 'array', 'min:1'],
            'bought.*' => ['uuid'],
        ];
    }
}
