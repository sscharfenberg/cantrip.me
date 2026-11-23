<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use App\Models\Container;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates and authorises the bulk move endpoint: every requested
 * card-stack ID must belong to the current user.
 *
 * The "ID does not exist" case is intentionally left for the service's
 * 404 check ({@see CardStackService::moveToContainer}) — that's an
 * existence concern, not an authorisation one. This request only catches
 * foreign-owner stacks, which would otherwise leak silently.
 *
 * Target container ownership is checked separately via
 * {@see CardStackService::resolveOwnedContainer}.
 *
 * Postgres rejects a malformed uuid with an error rather than an empty
 * result, so no id reaches a query before it is known to be a uuid:
 * `authorize()` runs before the rules and looks at well-formed ids only (a
 * malformed one then fails `card_stack_ids.*`), and the after-hook skips
 * ids that failed.
 */
class MoveSelectedCardStacksRequest extends FormRequest
{
    public function authorize(): bool
    {
        $userId = $this->user()?->id;
        if ($userId === null) {
            return false;
        }

        // Pull only id+user_id for the matching rows; if any *found* row
        // belongs to another user, fail. Missing IDs return fewer rows
        // and pass this check — the service then rejects them with 404.
        $rows = CardStack::query()
            ->whereIn('id', $this->wellFormedIds())
            ->get(['id', 'user_id']);

        return $rows->every(fn (CardStack $s): bool => $s->user_id === $userId);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'card_stack_ids' => ['required', 'array', 'min:1'],
            'card_stack_ids.*' => ['required', 'uuid'],
            'container_id' => ['nullable', 'uuid', Rule::exists(Container::class, 'id')],
        ];
    }

    /**
     * Block bulk container moves when any selected stack is claimed by
     * a deck. See {@see UpdateCardStackRequest::withValidator} for the
     * single-stack equivalent and rationale.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['card_stack_ids', 'card_stack_ids.*'])) {
                return;
            }

            $hasClaimed = CardStack::query()
                ->whereIn('id', $this->wellFormedIds())
                ->whereHas('deckCards')
                ->exists();

            if ($hasClaimed) {
                $validator->errors()->add(
                    'container_id',
                    __('collection.errors.cannot_move_claimed_stack'),
                );
            }
        });
    }

    /**
     * The requested ids that are uuids — the only ones a query may see.
     *
     * @return list<string>
     */
    private function wellFormedIds(): array
    {
        return array_values(array_filter(
            (array) $this->input('card_stack_ids', []),
            fn (mixed $id): bool => is_string($id) && Str::isUuid($id),
        ));
    }
}
