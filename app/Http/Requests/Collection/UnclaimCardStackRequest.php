<?php

namespace App\Http\Requests\Collection;

use App\Models\CardStack;
use App\Models\Container;
use App\Traits\ReturnsToPage;
use Closure;
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
    use ReturnsToPage;

    /** `move_to` value that takes the stack out of every container. */
    public const UNSORTED = 'unsorted';

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
            'move_to' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== self::UNSORTED && $this->ownedContainerId($value) === null) {
                        $fail(__('collection.errors.container_not_found'));
                    }
                },
            ],
            'remove_from_decks' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Where the stack should go once unclaimed: false to stay put, null
     * for unsorted, or the id — as stored — of one of the user's
     * containers.
     */
    public function moveTarget(): string|false|null
    {
        $moveTo = $this->validated('move_to');

        return match ($moveTo) {
            null, '' => false,
            self::UNSORTED => null,
            default => $this->ownedContainerId($moveTo),
        };
    }

    /** The id of the user's container `$id` names, or null when it names none of theirs. */
    private function ownedContainerId(mixed $id): ?string
    {
        if (! is_string($id)) {
            return null;
        }

        return Container::query()
            ->whereKey($id)
            ->where('user_id', $this->user()?->id)
            ->value('id');
    }
}
