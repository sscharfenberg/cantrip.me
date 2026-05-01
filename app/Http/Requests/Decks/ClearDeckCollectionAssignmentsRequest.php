<?php

namespace App\Http\Requests\Decks;

use App\Models\Deck;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the destructive "Clear all collection assignments" action
 * — only the deck owner may detach every pivot row and reset the sticky
 * mode pin.
 */
class ClearDeckCollectionAssignmentsRequest extends FormRequest
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
