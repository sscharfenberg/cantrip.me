<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use App\Traits\MovesFreedStacks;
use App\Traits\ReturnsToPage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the card-stack unclaim endpoint (Phase 2.7): only the
 * owner may detach every deck claim from one of their stacks.
 *
 * Ownership of the route-bound `cardStack` is the gate. The tables'
 * unclaim dialog can do two more things in the same step: move the freed
 * stack (`move_to`: one of the user's containers, or `unsorted` for none;
 * without it the stack stays where it is), and take its copies out of the
 * claiming decks as well (`remove_from_decks`).
 */
class UnclaimCardStackRequest extends FormRequest
{
    use MovesFreedStacks;
    use ReturnsToPage;

    public function authorize(): bool
    {
        $cardStack = $this->route('cardStack');

        return $cardStack instanceof CardStack && $cardStack->user_id === $this->user()?->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->moveToRules(),
            'remove_from_decks' => ['sometimes', 'boolean'],
        ];
    }
}
