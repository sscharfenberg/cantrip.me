<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the bulk move endpoint: every requested card-stack ID must
 * belong to the current user.
 *
 * The "ID does not exist" case is intentionally left for the service's
 * 404 check ({@see CardStackService::moveToContainer}) — that's an
 * existence concern, not an authorisation one. This request only catches
 * foreign-owner stacks, which would otherwise leak silently.
 *
 * Target container ownership is checked separately via
 * {@see CardStackService::resolveOwnedContainer}.
 */
class MoveSelectedCardStacksRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var array<int, string> $ids */
        $ids = (array) $this->input('card_stack_ids', []);
        $userId = $this->user()?->id;
        if ($userId === null) {
            return false;
        }

        // Pull only id+user_id for the matching rows; if any *found* row
        // belongs to another user, fail. Missing IDs return fewer rows
        // and pass this check — the service then rejects them with 404.
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
