<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the card-stack unclaim endpoint (Phase 2.7): only the
 * owner may detach every deck claim from one of their stacks.
 *
 * Mirrors {@see DeleteCardStackRequest}'s shape — no payload to
 * validate; ownership of the route-bound `cardStack` is the gate.
 */
class UnclaimCardStackRequest extends FormRequest
{
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
        return [];
    }
}
