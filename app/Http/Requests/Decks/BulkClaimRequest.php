<?php

namespace App\Http\Requests\Decks;

use App\Models\Container;
use App\Models\Deck;
use App\Services\DeckCollectionStatusService;
use App\Services\DeckFinalizeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorises the BulkClaim page (route `/decks/{deck}/bulk-claim`).
 *
 * Owner-only AND gated to mode C — bulk-claim only makes sense for
 * decks that have explicit per-card tracking. Mode-A users have no
 * collection plumbing; mode-B users opt out of per-card claims by
 * design.
 *
 * Validation covers both the GET (no payload) and the POST (assignment
 * payload + optional deckbox + optional "bought new" map). The shape
 * of `assignments` is `[deck_card_id => [card_stack_id, ...]]` — a
 * per-deck-card list of stacks the user wants to claim. Picked stacks
 * may be of any printing of the same oracle card; the service swaps
 * `deck_cards.default_card_id` to the picked stack's printing when
 * they differ. The shape of `buy_new` is `[deck_card_id => bool]` —
 * when true, the service pads the row with a freshly-created
 * card_stack covering any uncovered slots. See
 * {@see DeckFinalizeService::persistAssignments} for the math.
 */
class BulkClaimRequest extends FormRequest
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
            'assignments' => ['nullable', 'array'],
            'assignments.*' => ['array'],
            'assignments.*.*' => ['uuid'],
            'buy_new' => ['nullable', 'array'],
            'buy_new.*' => ['boolean'],
            'container_id' => [
                'nullable',
                Rule::exists(Container::class, 'id')
                    ->where('user_id', $this->user()?->id),
            ],
        ];
    }
}
