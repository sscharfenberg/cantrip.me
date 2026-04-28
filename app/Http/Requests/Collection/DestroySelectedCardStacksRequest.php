<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the bulk delete endpoint: every requested card-stack ID
 * must belong to the current user.
 *
 * The "ID does not exist" case is intentionally left for the service's
 * 404 check ({@see CardStackService::deleteSelected}) — that's an
 * existence concern, not an authorisation one.
 */
class DestroySelectedCardStacksRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var array<int, string> $ids */
        $ids = (array) $this->input('card_stack_ids', []);
        $userId = $this->user()?->id;
        if ($userId === null) {
            return false;
        }

        $rows = CardStack::query()
            ->whereIn('id', $ids)
            ->get(['id', 'user_id']);

        return $rows->every(fn (CardStack $s): bool => $s->user_id === $userId);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
